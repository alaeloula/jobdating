<?php

namespace App\Services\Imports;

use App\Enums\AuditAction;
use App\Models\Company;
use App\Models\CompanyImport;
use App\Models\Event;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Companies\CompanyNameLock;
use App\Services\Companies\CompanyService;
use App\Services\Passwords\PasswordGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Traitement d'un import d'entreprises confirmé (plan T-64 v1.3, § 6), sur le modèle de T-13.
 *
 * - Prise en charge EXCLUSIVE : « en attente » → « en cours ».
 * - Mots de passe générés et hachés HORS transaction, réutilisés par les reprises.
 * - UNE transaction : verrou des noms → événement (partagé) → revalidation → comptes, fiches,
 *   participations ; finalisation dans la même transaction. Rien de partiel en cas d'échec.
 * - Erreurs EXPURGÉES : seul un message générique est conservé.
 */
class CompanyImportRunner
{
    public const CONFLICT_ERROR = 'Une entreprise ou un nom d’utilisateur a été pris entre-temps, ou l’événement n’est plus disponible : relancez l’aperçu.';

    public const TECHNICAL_ERROR = 'Erreur technique : recommencez l’import.';

    public const ABANDONED_ERROR = 'Import interrompu : recommencez l’import.';

    private const ATTEMPTS = 3;

    public function __construct(
        private readonly CompanyService $companies,
        private readonly AuditLogger $audit,
    ) {}

    public function run(int $importId): void
    {
        $claimed = CompanyImport::query()->whereKey($importId)->where('status', CompanyImport::PENDING)
            ->update(['status' => CompanyImport::PROCESSING, 'started_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return; // Déjà pris en charge, terminé, en échec ou purgé.
        }

        $import = CompanyImport::query()->with('author')->findOrFail($importId);
        $author = $import->author;
        $previous = Auth::guard('web')->user();
        if ($author !== null) {
            Auth::guard('web')->setUser($author);
        }

        try {
            /** @var list<array{line: int, name: string, key: string, username: string}> $rows */
            $rows = $import->payload['rows'] ?? [];
            $secrets = [];
            foreach ($rows as $row) {
                $password = PasswordGenerator::generate();
                $secrets[$row['line']] = ['password' => $password, 'hash' => Hash::make($password)];
            }

            for ($attempt = 1; ; $attempt++) {
                try {
                    $this->attempt($import, $rows, $secrets, $author ?? throw new ImportConflictException);

                    return;
                } catch (QueryException $e) {
                    if (! $this->isDeadlock($e)) {
                        throw $e;
                    }
                    if ($attempt >= self::ATTEMPTS) {
                        throw new RuntimeException('Reprises épuisées.');
                    }
                }
            }
        } catch (ImportConflictException) {
            $this->markFailed($importId, self::CONFLICT_ERROR);
        } catch (Throwable $e) {
            Log::error('Import d’entreprises en échec', ['import' => $importId, 'exception' => $e::class]);
            $this->markFailed($importId, self::TECHNICAL_ERROR);
        } finally {
            if ($previous !== null) {
                Auth::guard('web')->setUser($previous);
            } else {
                Auth::guard('web')->forgetUser();
            }
        }
    }

    /**
     * Échec : seulement si l'import n'est pas déjà terminé ou en échec.
     *
     * @param  (callable(Builder<CompanyImport>): mixed)|null  $constraint  condition reprise dans la même mise à jour atomique
     */
    public function markFailed(int $importId, string $message, ?callable $constraint = null): void
    {
        $import = CompanyImport::query()->find($importId);
        $affected = CompanyImport::query()->whereKey($importId)->whereIn('status', CompanyImport::OPEN)
            ->when($constraint !== null, fn ($q) => $q->where($constraint))
            ->update([
                'status' => CompanyImport::FAILED,
                'payload' => null,
                'error' => $message,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        if ($affected === 1 && $import !== null) {
            $this->audit->record(AuditAction::CompanyImportFailed, $import, new: ['motif' => $message], actor: $import->author);
        }
    }

    /**
     * @param  list<array{line: int, name: string, key: string, username: string}>  $rows
     * @param  array<int, array{password: string, hash: string}>  $secrets
     */
    private function attempt(CompanyImport $import, array $rows, array $secrets, User $author): void
    {
        DB::transaction(function () use ($import, $rows, $secrets, $author): void {
            CompanyNameLock::acquire();
            $event = $import->event_id === null ? null : Event::query()->whereKey($import->event_id)->sharedLock()->first();
            if ($event === null || $event->isArchived() || $event->isFinished()) {
                throw new ImportConflictException;
            }

            // Revalidation sous les verrous, avant la première écriture.
            if (Company::query()->whereIn('name_key', array_column($rows, 'key'))->exists()
                || User::withTrashed()->whereIn('username', array_column($rows, 'username'))->exists()) {
                throw new ImportConflictException;
            }

            $credentials = [];
            foreach ($rows as $row) {
                $company = $this->companies->importCreate($event, $row['name'], $row['username'], $secrets[$row['line']]['hash'], $author);
                $credentials[] = [
                    'company_id' => $company->id,
                    'user_id' => (int) $company->user_id,
                    'name' => $row['name'],
                    'username' => $row['username'],
                    'password' => $secrets[$row['line']]['password'],
                    'version' => 1,
                    'hash' => $secrets[$row['line']]['hash'],
                ];
            }

            $done = new CompanyImport;
            $done->credentials = $credentials;
            $affected = CompanyImport::query()->whereKey($import->id)->where('status', CompanyImport::PROCESSING)->update([
                'status' => CompanyImport::DONE,
                'credentials' => $done->getAttributes()['credentials'],
                'payload' => null,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            if ($affected !== 1) {
                throw new RuntimeException('Import déjà terminal.');
            }
            $this->audit->record(AuditAction::CompanyImportCompleted, $import, new: [
                'evenement' => $import->event_name,
                'creees' => count($credentials),
                'deja_existantes' => $import->existing_count,
                'doublons' => $import->duplicate_count,
                'rejetees' => $import->rejected_count,
                'non_confirmees' => $import->unconfirmed_count,
            ], actor: $author);
        }, 1);
    }

    private function isDeadlock(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['40001', '1213'], true)
            || in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true);
    }
}
