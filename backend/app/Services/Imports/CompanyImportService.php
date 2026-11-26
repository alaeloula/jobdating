<?php

namespace App\Services\Imports;

use App\Enums\AuditAction;
use App\Jobs\ProcessCompanyImport;
use App\Models\CompanyImport;
use App\Models\Event;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Aperçu et confirmation d'un import d'entreprises (plan T-64 v1.3, §§ 5 et 6). Le classeur n'est
 * jamais conservé : il est relu à la confirmation.
 */
class CompanyImportService
{
    public function __construct(
        private readonly CompanyWorkbookReader $reader,
        private readonly CompanyImportPlanner $planner,
        private readonly AuditLogger $audit,
        private readonly CompanyImportRunner $runner,
    ) {}

    /**
     * @return array{event: Event, plan: array{rows: list<array{line: int, decision: string, reason: string|null, name: string|null, key: string|null, username: string|null}>, counts: array{create: int, existing: int, duplicate: int, reject: int, unconfirmed: int}, signaled: list<array{line: int, reason: string, name: string|null}>, fingerprint: string}}
     */
    public function preview(UploadedFile $file, int $eventId): array
    {
        $event = $this->event($eventId);
        $rows = $this->reader->read((string) $file->getRealPath(), $file->getClientOriginalName());
        $hash = (string) hash_file('sha256', (string) $file->getRealPath());

        return ['event' => $event, 'plan' => $this->planner->plan($rows, $hash, $event)];
    }

    /**
     * Confirmation idempotente : même clé et même empreinte → même import ; même clé et autre
     * empreinte → refus. Empreinte différente de l'aperçu → 409, rien n'est écrit.
     */
    public function confirm(UploadedFile $file, int $eventId, string $fingerprint, string $key, User $admin): CompanyImport
    {
        $existing = CompanyImport::query()->where('confirmation_key', $key)->first();
        if ($existing !== null) {
            return $this->sameRequest($existing, $fingerprint);
        }

        $preview = $this->preview($file, $eventId);
        $plan = $preview['plan'];
        if (! hash_equals($plan['fingerprint'], $fingerprint)) {
            throw new ImportChangedException;
        }
        if ($plan['counts']['create'] === 0) {
            throw ValidationException::withMessages(['file' => 'Aucune entreprise à créer : rien à importer.']);
        }

        // Liste blanche : numéro, nom, clé et nom d'utilisateur des lignes à créer.
        $payload = [];
        foreach ($plan['rows'] as $row) {
            if ($row['decision'] === CompanyImportPlanner::CREATE) {
                $payload[] = ['line' => $row['line'], 'name' => (string) $row['name'], 'key' => (string) $row['key'], 'username' => (string) $row['username']];
            }
        }

        try {
            return DB::transaction(function () use ($preview, $plan, $payload, $file, $fingerprint, $key, $admin): CompanyImport {
                $import = new CompanyImport;
                $import->forceFill([
                    'user_id' => $admin->id,
                    'event_id' => $preview['event']->id,
                    'event_name' => mb_substr($preview['event']->fullName(), 0, 180),
                    'filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'confirmation_key' => $key,
                    'fingerprint' => $fingerprint,
                    'status' => CompanyImport::PENDING,
                    'created_count' => $plan['counts']['create'],
                    'existing_count' => $plan['counts']['existing'],
                    'duplicate_count' => $plan['counts']['duplicate'],
                    'rejected_count' => $plan['counts']['reject'],
                    'unconfirmed_count' => $plan['counts']['unconfirmed'],
                    'signaled' => $plan['signaled'],
                    'payload' => ['rows' => $payload],
                ])->save();

                $this->audit->record(AuditAction::CompanyImportConfirmed, $import, new: [
                    'fichier' => $import->filename,
                    'evenement' => $import->event_name,
                    'a_creer' => $plan['counts']['create'],
                    'deja_existantes' => $plan['counts']['existing'],
                    'doublons' => $plan['counts']['duplicate'],
                    'rejetees' => $plan['counts']['reject'],
                    'non_confirmees' => $plan['counts']['unconfirmed'],
                ], actor: $admin);

                // Mise en file APRÈS la validation : la tâche ne transporte que l'identifiant.
                DB::afterCommit(function () use ($import): void {
                    try {
                        ProcessCompanyImport::dispatch($import->id)->onConnection(LearnerImportService::QUEUE_CONNECTION)->onQueue(LearnerImportService::QUEUE);
                    } catch (Throwable $e) {
                        Log::error('Mise en file de l’import d’entreprises impossible', ['import' => $import->id, 'exception' => $e::class]);
                        $this->runner->markFailed($import->id, CompanyImportRunner::TECHNICAL_ERROR);
                    }
                });

                return $import;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->sameRequest(CompanyImport::query()->where('confirmation_key', $key)->firstOrFail(), $fingerprint);
        }
    }

    private function event(int $eventId): Event
    {
        $event = Event::query()->find($eventId);
        if ($event === null || $event->isArchived() || $event->isFinished()) {
            throw ValidationException::withMessages(['event_id' => 'Cet événement n’est plus proposé : choisissez-en un autre.']);
        }

        return $event;
    }

    private function sameRequest(CompanyImport $import, string $fingerprint): CompanyImport
    {
        if (! hash_equals($import->fingerprint, $fingerprint)) {
            throw new ConfirmationKeyReusedException;
        }

        return $import;
    }
}
