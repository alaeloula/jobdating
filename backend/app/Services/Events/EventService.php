<?php

namespace App\Services\Events;

use App\Contracts\EventUsage;
use App\Enums\AuditAction;
use App\Models\Event;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Événements (ADM-05, plan T-14, § 4). L'année scolaire vient toujours de la date. Toutes les écritures
 * sont en transaction, rejouée en cas d'interblocage ; après 3 essais : 409 « réessayez ».
 */
class EventService
{
    private const ATTEMPTS = 3;

    private const YEAR_SUFFIX = '/(^|\s)\d{4}(\s*[\/-]\s*\d{2,4})?$/u';

    public function __construct(
        private readonly EventUsage $usage,
        private readonly EventParticipationService $participation,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $admin): Event
    {
        $this->assertName((string) $data['name']);
        $date = (string) $data['date'];
        if ($date < Event::today()) {
            throw ValidationException::withMessages(['date' => 'La date ne peut pas être antérieure au jour de création de l’événement.']);
        }

        return $this->guarded(fn () => DB::transaction(function () use ($data, $date, $admin): Event {
            $this->assertYearFree(Event::schoolYearFor($date), null);

            $event = new Event;
            $event->fill($data);
            $event->forceFill(['school_year' => Event::schoolYearFor($date)])->save();
            $this->audit->record(AuditAction::EventCreated, $event, new: $this->snapshot($event), actor: $admin);

            return $event;
        }, self::ATTEMPTS));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Event $event, array $data, User $admin): Event
    {
        if (array_key_exists('name', $data)) {
            $this->assertName((string) $data['name']);
        }

        return $this->guarded(fn () => DB::transaction(function () use ($event, $data, $admin): Event {
            $locked = $this->lock($event);
            if ($locked->isArchived()) {
                throw EventRuleException::archived();
            }
            $this->assertCoordinates($locked, $data);

            // État « avant » capturé avant TOUTE modification, année scolaire comprise (revue T-14, constat 4).
            $before = $this->snapshot($locked);

            if (array_key_exists('date', $data) && (string) $data['date'] !== $locked->date->toDateString()) {
                $date = (string) $data['date'];
                // RG-EVT-04 : la borne reste le jour de CRÉATION, y compris en modification.
                if ($date < $locked->createdOn()) {
                    throw ValidationException::withMessages(['date' => 'La date ne peut pas être antérieure au jour de création de l’événement.']);
                }
                if ($this->usage->forEvent($locked) > 0) {
                    throw ValidationException::withMessages(['date' => 'Cet événement a des passages : sa date n’est plus modifiable.']);
                }
                $year = Event::schoolYearFor($date);
                $this->assertYearFree($year, $locked->id);
                $locked->forceFill(['school_year' => $year]);
            }

            $locked->fill($data);
            if ($locked->isDirty()) {
                $locked->save();
                $after = $this->snapshot($locked);
                $changed = array_keys(array_filter($after, fn ($value, $key) => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));
                if ($changed !== []) {
                    $this->audit->record(AuditAction::EventUpdated, $locked, array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)), actor: $admin);
                }
            }

            return $locked;
        }, self::ATTEMPTS));
    }

    /**
     * Désigne l'événement courant et inscrit les apprenants actifs (RG-EVT-01, RG-PAR-06). Verrou
     * exclusif AVANT toute lecture de la transaction : voir `enrollAllActive`.
     *
     * @return array{event: Event, report: array{enrolled: int, already: int, ignored_deactivated: int, ignored_removed: int}}
     */
    public function designate(Event $event, User $admin): array
    {
        return $this->guarded(fn () => DB::transaction(function () use ($event, $admin): array {
            DB::table('event_locks')->where('id', 1)->lockForUpdate()->first();

            $previousId = Event::query()->current()->lockForUpdate()->value('id');
            $ids = array_values(array_unique(array_filter([(int) $event->id, $previousId === null ? null : (int) $previousId])));
            sort($ids);
            $rows = Event::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $target = $rows->get($event->id) ?? abort(404);
            if ($target->isArchived()) {
                throw EventRuleException::archived();
            }
            if ($target->isFinished()) {
                throw new EventRuleException('event_finished', 'Un événement terminé ne peut pas devenir courant.');
            }

            $changed = ! $target->is_current;
            if ($changed) {
                $previous = $previousId === null ? null : $rows->get((int) $previousId);
                $previous?->forceFill(['is_current' => false])->save();
                $target->forceFill(['is_current' => true])->save();
            }

            $report = $this->participation->enrollAllActive($target);
            if ($changed || $report['enrolled'] > 0) {
                // Une seule ligne, avec des comptes : ni nom ni CNIE.
                $this->audit->record(AuditAction::EventCurrentSet, $target, new: [
                    'inscrits' => $report['enrolled'],
                    'deja_inscrits' => $report['already'],
                    'ignores_desactives' => $report['ignored_deactivated'],
                    'ignores_retires' => $report['ignored_removed'],
                ], actor: $admin);
            }

            return ['event' => $target, 'report' => $report];
        }, self::ATTEMPTS));
    }

    /** Retire le statut courant sans en désigner un autre (décision 7) ; les inscriptions restent. */
    public function unsetCurrent(User $admin): void
    {
        $this->guarded(fn () => DB::transaction(function () use ($admin): void {
            DB::table('event_locks')->where('id', 1)->lockForUpdate()->first();
            $current = Event::query()->current()->lockForUpdate()->first();
            if ($current === null) {
                return;
            }

            $current->forceFill(['is_current' => false])->save();
            $this->audit->record(AuditAction::EventCurrentUnset, $current, actor: $admin);
        }, self::ATTEMPTS));
    }

    public function archive(Event $event, User $admin): Event
    {
        return $this->guarded(fn () => DB::transaction(function () use ($event, $admin): Event {
            $locked = $this->lock($event);
            if ($locked->is_current) {
                throw new EventRuleException('event_current', 'L’événement courant ne peut pas être archivé : retirez d’abord son statut.');
            }
            if (! $locked->isArchived()) {
                $locked->forceFill(['archived_at' => now()])->save();
                $this->audit->record(AuditAction::EventArchived, $locked, actor: $admin);
            }

            return $locked;
        }, self::ATTEMPTS));
    }

    public function unarchive(Event $event, User $admin): Event
    {
        return $this->guarded(fn () => DB::transaction(function () use ($event, $admin): Event {
            $locked = $this->lock($event);
            if ($locked->isArchived()) {
                $locked->forceFill(['archived_at' => null])->save();
                $this->audit->record(AuditAction::EventUnarchived, $locked, actor: $admin);
            }

            return $locked;
        }, self::ATTEMPTS));
    }

    /** RG-EVT-03 : refusée avec des passages (archivage proposé) ou pour l'événement courant. */
    public function delete(Event $event, User $admin): void
    {
        $this->guarded(fn () => DB::transaction(function () use ($event, $admin): void {
            $locked = $this->lock($event);
            // Archivé = lecture seule, participations comprises (décision 3, revue T-14, constat 2).
            if ($locked->isArchived()) {
                throw EventRuleException::archived();
            }
            if ($locked->is_current) {
                throw new EventRuleException('event_current', 'L’événement courant ne peut pas être supprimé : retirez d’abord son statut.');
            }
            if ($this->usage->forEvent($locked) > 0) {
                throw new EventRuleException('event_has_passages', 'Cet événement contient des passages : il ne peut pas être supprimé, vous pouvez l’archiver.');
            }

            $this->audit->record(AuditAction::EventDeleted, $locked, old: $this->snapshot($locked), actor: $admin);
            $locked->delete();
        }, self::ATTEMPTS));
    }

    private function lock(Event $event): Event
    {
        return Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
    }

    private function assertName(string $name): void
    {
        if (preg_match(self::YEAR_SUFFIX, trim($name)) === 1) {
            throw ValidationException::withMessages(['name' => 'L’année est ajoutée automatiquement : retirez-la du nom.']);
        }
    }

    /**
     * Latitude et longitude : ensemble ou aucune, sur la paire OBTENUE après la modification partielle
     * (revue T-14, constat 3).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertCoordinates(Event $event, array $data): void
    {
        $latitude = array_key_exists('latitude', $data) ? $data['latitude'] : $event->latitude;
        $longitude = array_key_exists('longitude', $data) ? $data['longitude'] : $event->longitude;
        if (($latitude === null) !== ($longitude === null)) {
            throw ValidationException::withMessages([
                $latitude === null ? 'latitude' : 'longitude' => 'Renseignez la latitude et la longitude, ou aucune des deux.',
            ]);
        }
    }

    private function assertYearFree(string $year, ?int $exceptId): void
    {
        $taken = Event::query()->where('school_year', $year)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->exists();
        if ($taken) {
            throw $this->yearTaken($year);
        }
    }

    private function yearTaken(string $year): ValidationException
    {
        return ValidationException::withMessages(['date' => "Un événement existe déjà pour l’année scolaire {$year}."]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Event $event): array
    {
        return [
            'nom' => $event->fullName(),
            'date' => $event->date->toDateString(),
            'lieu' => $event->location,
            'latitude' => $event->latitude,
            'longitude' => $event->longitude,
            'description' => $event->description,
        ];
    }

    /**
     * Collision d'année au dernier moment : 422 sur la date. Interblocage ou attente trop longue
     * après les reprises : 409, rien n'est modifié.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function guarded(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'events_school_year_unique')) {
                throw ValidationException::withMessages(['date' => 'Un événement existe déjà pour cette année scolaire.']);
            }
            throw $e;
        } catch (QueryException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) {
                throw EventRuleException::busy();
            }
            throw $e;
        }
    }
}
