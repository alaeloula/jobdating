<?php

namespace App\Services\Events;

use App\Contracts\EventUsage;
use App\Enums\AuditAction;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventCompany;
use App\Models\EventLearner;
use App\Models\Learner;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Participants d'un événement (ADM-07, plan T-14, §§ 5 et 6).
 *
 * ORDRE DES VERROUS : `event_locks` → ligne de l'événement → classes → comptes → fiches.
 * Les chemins qui rendent un apprenant actif appellent `beginActivation()` EN PREMIER dans leur
 * transaction ; la désignation du courant prend le même verrou en mode exclusif.
 */
class EventParticipationService
{
    private const ATTEMPTS = 3;

    private const CHUNK = 500;

    public function __construct(
        private readonly EventUsage $usage,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------ Inscription automatique

    /**
     * À appeler EN PREMIER dans la transaction d'un chemin qui rend un apprenant actif. Verrou partagé,
     * puis lecture simple de l'événement courant : la photo de la transaction est prise après le
     * verrou, et aucune désignation ne peut changer le courant avant la validation.
     */
    public function beginActivation(): ?int
    {
        DB::table('event_locks')->where('id', 1)->sharedLock()->first();
        $id = Event::query()->current()->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Inscription idempotente, par identifiant croissant et par paquets. Une ligne existante, retirée
     * ou non, n'est jamais modifiée (décision 1).
     *
     * @param  list<int>  $learnerIds
     * @return int lignes réellement ajoutées
     */
    public function enroll(?int $eventId, array $learnerIds): int
    {
        if ($eventId === null || $learnerIds === []) {
            return 0;
        }
        $learnerIds = array_values(array_unique($learnerIds));
        sort($learnerIds);

        $added = 0;
        $now = now();
        foreach (array_chunk($learnerIds, self::CHUNK) as $chunk) {
            $added += DB::table('event_learners')->insertOrIgnore(array_map(
                fn (int $id) => ['event_id' => $eventId, 'learner_id' => $id, 'enrolled_at' => $now],
                $chunk,
            ));
        }

        return $added;
    }

    /**
     * Inscription de tous les apprenants actifs (RG-PAR-06). L'appelant détient le verrou EXCLUSIF
     * sur `event_locks`, pris avant toute lecture de sa transaction : la lecture simple ci-dessous voit
     * donc toutes les créations et réactivations validées, sans verrouiller comptes ni fiches.
     *
     * @return array{enrolled: int, already: int, ignored_deactivated: int, ignored_removed: int}
     */
    public function enrollAllActive(Event $event): array
    {
        $active = Learner::query()->join('users', 'users.id', '=', 'learners.user_id')
            ->whereNull('users.deactivated_at')->whereNull('users.deleted_at')
            ->orderBy('learners.id')->pluck('learners.id')->map(fn ($id) => (int) $id)->all();
        $deactivated = Learner::query()->join('users', 'users.id', '=', 'learners.user_id')
            ->whereNotNull('users.deactivated_at')->whereNull('users.deleted_at')->count();

        $rows = DB::table('event_learners')->where('event_id', $event->id)->get(['learner_id', 'removed_at']);
        $existing = [];
        foreach ($rows as $row) {
            $existing[(int) $row->learner_id] = $row->removed_at !== null;
        }

        $missing = [];
        $already = 0;
        $removed = 0;
        foreach ($active as $id) {
            if (! array_key_exists($id, $existing)) {
                $missing[] = $id;
            } elseif ($existing[$id]) {
                $removed++;
            } else {
                $already++;
            }
        }

        return [
            'enrolled' => $this->enroll($event->id, $missing),
            'already' => $already,
            'ignored_deactivated' => $deactivated,
            'ignored_removed' => $removed,
        ];
    }

    // ------------------------------------------------------------------ Apprenants

    public function removeLearner(Event $event, Learner $learner, User $admin): void
    {
        $this->guarded(fn () => DB::transaction(function () use ($event, $learner, $admin): void {
            $locked = $this->lockWritable($event);
            $row = $this->learnerRow($locked, $learner);
            if ($row->removed_at !== null) {
                return;
            }
            if ($this->usage->forLearner($locked, $learner) > 0) {
                throw new EventRuleException('participant_has_passages', EventRuleException::PARTICIPANT_HAS_PASSAGES);
            }

            $row->forceFill(['removed_at' => now()])->save();
            $this->audit->record(AuditAction::EventLearnerRemoved, $learner, new: ['evenement' => $locked->fullName()], actor: $admin);
        }, self::ATTEMPTS));
    }

    public function reinstateLearner(Event $event, Learner $learner, User $admin): void
    {
        $this->guarded(fn () => DB::transaction(function () use ($event, $learner, $admin): void {
            $locked = $this->lockWritable($event);
            $row = $this->learnerRow($locked, $learner);
            if ($row->removed_at === null) {
                return;
            }

            $row->forceFill(['removed_at' => null])->save();
            $this->audit->record(AuditAction::EventLearnerReinstated, $learner, new: ['evenement' => $locked->fullName()], actor: $admin);
        }, self::ATTEMPTS));
    }

    // ------------------------------------------------------------------ Entreprises

    /**
     * Création d'entreprise (RG-ENTR-03) : à appeler en premier dans SA transaction. L'archivage et la
     * suppression prennent un verrou exclusif sur cette ligne : ils attendent la validation.
     */
    public function lockEligibleEvent(int $eventId): Event
    {
        $event = Event::query()->whereKey($eventId)->sharedLock()->first();
        if ($event === null || $event->isArchived() || $event->isFinished()) {
            throw ValidationException::withMessages(['event_id' => 'Cet événement n’est plus proposé : choisissez-en un autre.']);
        }

        return $event;
    }

    public function attachNewCompany(Event $event, Company $company, ?string $floor, ?string $table, User $admin): void
    {
        EventCompany::query()->create([
            'event_id' => $event->id,
            'company_id' => $company->id,
            'floor' => $floor,
            'table_label' => $table,
        ]);
        $this->audit->record(AuditAction::EventCompanyAdded, $company, new: [
            'evenement' => $event->fullName(),
            'etage' => $floor,
            'table' => $table,
        ], actor: $admin);
    }

    /**
     * Ajout en masse (RG-PAR-07). Comptes désactivés ou supprimés ignorés ; une ligne de journal.
     *
     * @param  list<int>  $companyIds
     * @return array{added: int, already: int, ignored: int}
     */
    public function addCompanies(Event $event, array $companyIds, User $admin): array
    {
        return $this->guarded(fn () => DB::transaction(function () use ($event, $companyIds, $admin): array {
            $locked = $this->lockWritable($event);
            $companyIds = array_values(array_unique($companyIds));
            sort($companyIds);

            $eligible = Company::query()->join('users', 'users.id', '=', 'companies.user_id')
                ->whereIn('companies.id', $companyIds)
                ->whereNull('users.deactivated_at')->whereNull('users.deleted_at')
                ->orderBy('companies.id')->pluck('companies.id')->map(fn ($id) => (int) $id)->all();

            $now = now();
            $added = 0;
            foreach (array_chunk($eligible, self::CHUNK) as $chunk) {
                $added += DB::table('event_companies')->insertOrIgnore(array_map(
                    fn (int $id) => ['event_id' => $locked->id, 'company_id' => $id, 'created_at' => $now, 'updated_at' => $now],
                    $chunk,
                ));
            }
            $report = ['added' => $added, 'already' => count($eligible) - $added, 'ignored' => count($companyIds) - count($eligible)];

            if ($added > 0) {
                $this->audit->record(AuditAction::EventCompanyAdded, $locked, new: [
                    'ajoutees' => $report['added'],
                    'deja_inscrites' => $report['already'],
                    'ignorees' => $report['ignored'],
                ], actor: $admin);
            }

            return $report;
        }, self::ATTEMPTS));
    }

    public function removeCompany(Event $event, Company $company, User $admin): void
    {
        $this->guarded(fn () => DB::transaction(function () use ($event, $company, $admin): void {
            $locked = $this->lockWritable($event);
            $row = EventCompany::query()->where('event_id', $locked->id)->where('company_id', $company->id)->firstOrFail();
            if ($this->usage->forCompany($locked, $company) > 0) {
                throw new EventRuleException('participant_has_passages', EventRuleException::PARTICIPANT_HAS_PASSAGES);
            }

            $row->delete();
            $this->audit->record(AuditAction::EventCompanyRemoved, $company, new: ['evenement' => $locked->fullName()], actor: $admin);
        }, self::ATTEMPTS));
    }

    /** Emplacement facultatif ; la dernière modification enregistrée est conservée (décision 9). */
    public function updateLocation(Event $event, Company $company, ?string $floor, ?string $table, User $actor): EventCompany
    {
        return $this->guarded(fn () => DB::transaction(function () use ($event, $company, $floor, $table, $actor): EventCompany {
            $locked = Event::query()->whereKey($event->id)->sharedLock()->firstOrFail();
            if ($locked->isArchived()) {
                throw EventRuleException::archived();
            }
            $row = EventCompany::query()->where('event_id', $locked->id)->where('company_id', $company->id)
                ->lockForUpdate()->firstOrFail();

            $before = ['etage' => $row->floor, 'table' => $row->table_label];
            $row->fill(['floor' => $floor, 'table_label' => $table]);
            if ($row->isDirty()) {
                $row->save();
                $this->audit->record(AuditAction::EventCompanyLocationUpdated, $company, $before + ['evenement' => $locked->fullName()], ['etage' => $floor, 'table' => $table], actor: $actor);
            }

            return $row;
        }, self::ATTEMPTS));
    }

    // ------------------------------------------------------------------ Lectures

    /**
     * Contexte de la fiche de connexion. Avec `$eventId` : cette participation, sinon 422 (à appeler
     * AVANT tout effet). Sans : l'événement courant si l'entreprise y participe, sinon aucun.
     *
     * @return array{id: int, name: string, date: string, location: string, floor: string|null, table_label: string|null}|null
     */
    public function credentialsContext(Company $company, ?int $eventId): ?array
    {
        $query = EventCompany::query()->with('event')->where('company_id', $company->id);
        if ($eventId !== null) {
            $row = $query->where('event_id', $eventId)->first();
            if ($row === null) {
                throw ValidationException::withMessages(['event_id' => 'Cette entreprise ne participe pas à cet événement.']);
            }

            return $this->placement($row);
        }

        $row = $query->whereHas('event', fn ($q) => $q->whereNotNull('current_marker'))->first();

        return $row === null ? null : $this->placement($row);
    }

    /**
     * Vue de l'entreprise connectée (décisions 10 et 12) : l'événement COURANT, si elle y participe.
     *
     * @return array{id: int, name: string, date: string, location: string, floor: string|null, table_label: string|null}|null
     */
    public function companyView(Company $company): ?array
    {
        return $this->credentialsContext($company, null);
    }

    /**
     * « Ma journée » : événement courant et entreprises présentes. Jamais d'identifiant de connexion,
     * de contact ni de donnée d'évaluation. Vide pour un apprenant retiré ou non inscrit.
     *
     * @return array{event: array<string, mixed>, companies: list<array<string, mixed>>}|null
     */
    public function learnerView(Learner $learner): ?array
    {
        $event = Event::query()->current()->first();
        if ($event === null || ! Learner::query()->whereKey($learner->id)->participatingIn($event->id)->exists()) {
            return null;
        }

        $companies = EventCompany::query()
            ->with('company.sector')
            ->where('event_id', $event->id)
            ->whereHas('company.user', fn ($u) => $u->whereNull('deactivated_at')->whereNull('deleted_at'))
            ->get()
            ->sortBy(fn (EventCompany $row) => mb_strtolower($row->company->name))
            ->map(fn (EventCompany $row) => [
                'id' => $row->company->id,
                'name' => $row->company->name,
                'sector' => $row->company->sector?->label,
                'floor' => $row->floor,
                'table_label' => $row->table_label,
            ])->values()->all();

        return [
            'event' => [
                'id' => $event->id,
                'name' => $event->fullName(),
                'date' => $event->date->toDateString(),
                'location' => $event->location,
                'description' => $event->description,
                'status' => $event->status(),
            ],
            'companies' => $companies,
        ];
    }

    // ------------------------------------------------------------------ Outils

    /**
     * @return array{id: int, name: string, date: string, location: string, floor: string|null, table_label: string|null}
     */
    private function placement(EventCompany $row): array
    {
        return [
            'id' => $row->event->id,
            'name' => $row->event->fullName(),
            'date' => $row->event->date->toDateString(),
            'location' => $row->event->location,
            'floor' => $row->floor,
            'table_label' => $row->table_label,
        ];
    }

    private function lockWritable(Event $event): Event
    {
        $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
        if ($locked->isArchived()) {
            throw EventRuleException::archived();
        }

        return $locked;
    }

    private function learnerRow(Event $event, Learner $learner): EventLearner
    {
        return EventLearner::query()->where('event_id', $event->id)->where('learner_id', $learner->id)
            ->lockForUpdate()->firstOrFail();
    }

    /**
     * Interblocage ou attente trop longue après les reprises : 409 « réessayez », rien n'est modifié.
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
        } catch (QueryException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) {
                throw EventRuleException::busy();
            }
            throw $e;
        }
    }
}
