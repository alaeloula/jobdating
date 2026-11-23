<?php

namespace App\Services\Imports;

use App\Enums\AuditAction;
use App\Models\Learner;
use App\Models\LearnerImport;
use App\Models\Referentials\Campus;
use App\Models\Referentials\Promotion;
use App\Models\Referentials\SchoolClass;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Learners\ClassMovedException;
use App\Services\Learners\LearnerService;
use App\Services\Passwords\PasswordGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Traitement d'un import confirmé (plan T-13 v2.1, §5), appelé par la tâche de file.
 *
 * - Prise en charge EXCLUSIVE : « en attente » → « en cours » par une mise à jour conditionnelle.
 * - Mots de passe préparés et hachés HORS transaction, pour TOUS les nouveaux comptes, et réutilisés
 *   par les reprises (jamais rehachés).
 * - UNE transaction, verrous dans l'ordre : classes → comptes → fiches, chaque fois par identifiant.
 * - Revalidation sous les verrous ; déplacement de classe ou interblocage → reprise complète (3 fois).
 * - Finalisation dans la MÊME transaction : comptes, rapport, mots de passe chiffrés, journal, statut
 *   « terminé », `payload` effacé. Un import terminé n'est jamais réécrit (C2).
 * - Erreurs EXPURGÉES : seul un message générique est conservé.
 */
class LearnerImportRunner
{
    public const CONFLICT_ERROR = 'Une CNIE ou un e-mail a été pris entre-temps, ou un référentiel a été désactivé : vérifiez l’aperçu et recommencez.';

    public const TECHNICAL_ERROR = 'Erreur technique : recommencez l’import.';

    public const ABANDONED_ERROR = 'Import interrompu : recommencez l’import.';

    private const ATTEMPTS = 3;

    public function __construct(
        private readonly LearnerService $learners,
        private readonly AuditLogger $audit,
    ) {}

    public function run(int $importId): void
    {
        $claimed = LearnerImport::query()->whereKey($importId)->where('status', LearnerImport::PENDING)
            ->update(['status' => LearnerImport::PROCESSING, 'started_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return; // Déjà pris en charge, terminé, en échec ou purgé.
        }

        $import = LearnerImport::query()->with('author')->findOrFail($importId);
        $author = $import->author;
        $previous = Auth::guard('web')->user();
        Auth::guard('web')->setUser($author);

        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = $import->payload['rows'] ?? [];
            // Identifiants préparés une seule fois (hachage hors transaction, réutilisé par les reprises).
            $credentials = [];
            foreach ($rows as $row) {
                if ($row['decision'] === LearnerImportPlanner::CREATE) {
                    $password = PasswordGenerator::generate();
                    $credentials[$row['line']] = ['password' => $password, 'hash' => Hash::make($password)];
                }
            }

            for ($attempt = 1; ; $attempt++) {
                try {
                    $this->attempt($import, $rows, $credentials, $author);

                    return;
                } catch (ClassMovedException|QueryException $e) {
                    if ($e instanceof QueryException && ! $this->isDeadlock($e)) {
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
            // Le message d'origine (valeurs SQL) n'est jamais conservé.
            Log::error('Import d’apprenants en échec', ['import' => $importId, 'exception' => $e::class]);
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
     * Échec : seulement si l'import n'est pas déjà terminé ou en échec (états terminaux protégés).
     */
    /**
     * @param  (callable(Builder<LearnerImport>): mixed)|null  $constraint
     *                                                                      condition supplémentaire vérifiée dans la MÊME mise à jour atomique (purge : ancienneté)
     */
    public function markFailed(int $importId, string $message, ?callable $constraint = null): void
    {
        $import = LearnerImport::query()->find($importId);
        $affected = LearnerImport::query()->whereKey($importId)->whereIn('status', LearnerImport::OPEN)
            ->when($constraint !== null, fn ($q) => $q->where($constraint))
            ->update([
                'status' => LearnerImport::FAILED,
                'payload' => null,
                'error' => $message,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        if ($affected === 1 && $import !== null) {
            $this->audit->record(AuditAction::LearnerImportFailed, $import, new: ['motif' => $message], actor: $import->author);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, array{password: string, hash: string}>  $credentials
     */
    private function attempt(LearnerImport $import, array $rows, array $credentials, ?User $author): void
    {
        // Apprenants connus : à mettre à jour ET inchangés (revalidés sans écriture, constat 7).
        $updates = array_filter($rows, fn (array $r) => in_array($r['decision'], [LearnerImportPlanner::UPDATE, LearnerImportPlanner::UNCHANGED], true));
        // Lecture FRAÎCHE (hors transaction) des classes actuelles des apprenants connus.
        $currentClasses = Learner::query()->whereIn('id', array_column($updates, 'learner_id'))->pluck('class_id')->map(fn ($id) => (int) $id)->all();

        // Classes des lignes (v2.2 : la classe vient de chaque ligne).
        $rowClasses = array_values(array_unique(array_map(fn (array $r) => (int) $r['class_id'], $rows)));

        DB::transaction(function () use ($import, $rows, $credentials, $author, $updates, $currentClasses, $rowClasses): void {
            // (a) Classes des lignes et classes actuelles des apprenants connus, par identifiant croissant.
            $classIds = array_values(array_unique([...$rowClasses, ...$currentClasses]));
            sort($classIds);
            $classes = SchoolClass::query()->whereIn('id', $classIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($rowClasses as $classId) {
                if (! $classes->get($classId)?->active) {
                    throw new ImportConflictException; // classe supprimée ou désactivée depuis l'aperçu
                }
            }
            if (! Campus::query()->whereKey($import->campus_id)->where('active', true)->exists()
                || ! Promotion::query()->whereKey($import->promotion_id)->where('active', true)->exists()) {
                throw new ImportConflictException;
            }

            // (b) Comptes : comptes connus et comptes portant les e-mails du fichier, par identifiant.
            $emails = array_column($rows, 'email');
            $users = User::withTrashed()
                ->where(fn ($q) => $q->whereIn('id', array_column($updates, 'user_id'))->orWhereIn('email', $emails))
                ->orderBy('id')->lockForUpdate()->get();
            $byEmail = $users->keyBy(fn (User $u) => Str::lower((string) $u->email));
            $byId = $users->keyBy('id');

            // (c) Fiches des CNIE du fichier, par identifiant.
            $learners = Learner::query()->whereIn('cnie', array_column($rows, 'cnie'))->orderBy('id')->lockForUpdate()->get()->keyBy('cnie');
            foreach ($learners as $learner) {
                if (! $classes->has($learner->class_id)) {
                    throw new ClassMovedException; // classe actuelle changée depuis la lecture fraîche
                }
            }

            $passwords = [];
            $data = fn (array $r) => [
                'first_name' => $r['first_name'], 'last_name' => $r['last_name'], 'email' => $r['email'], 'phone' => $r['phone'],
                'campus_id' => $import->campus_id, 'promotion_id' => $import->promotion_id, 'class_id' => (int) $r['class_id'],
            ];

            // Revalidation de TOUTES les lignes sous les verrous, avant la première écriture.
            foreach ($rows as $r) {
                $owner = $byEmail->get(Str::lower($r['email']));
                $learner = $learners->get($r['cnie']);
                if ($r['decision'] === LearnerImportPlanner::CREATE) {
                    if ($learner !== null || $owner !== null) {
                        throw new ImportConflictException;
                    }

                    continue;
                }
                $user = $byId->get($r['user_id']);
                if ($learner === null || $learner->id !== $r['learner_id'] || $user === null || $user->trashed()
                    || ($owner !== null && $owner->id !== $user->id)) {
                    throw new ImportConflictException;
                }
            }

            foreach ($rows as $r) {
                $learner = $learners->get($r['cnie']);
                if ($r['decision'] === LearnerImportPlanner::UNCHANGED) {
                    continue;
                }

                if ($r['decision'] === LearnerImportPlanner::CREATE) {
                    $credential = $credentials[$r['line']];
                    $this->learners->importCreate(['cnie' => $r['cnie']] + $data($r), $credential['password'], $credential['hash'], (bool) $r['active'], $author ?? throw new ImportConflictException);
                    if ($r['active']) {
                        $passwords[] = [
                            'first_name' => $r['first_name'], 'last_name' => $r['last_name'], 'email' => $r['email'],
                            'class' => (string) $classes->get((int) $r['class_id'])?->name, 'password' => $credential['password'],
                        ];
                    }

                    continue;
                }

                $user = $byId->get($r['user_id']);
                if ($learner === null || $user === null) {
                    throw new ImportConflictException;
                }
                $this->learners->importUpdate($learner, $user, $data($r), (bool) $r['active'], $author ?? throw new ImportConflictException);
            }

            // Finalisation dans la même transaction ; jamais sur un import déjà terminal.
            $done = new LearnerImport;
            $done->passwords = $passwords === [] ? null : $passwords;
            $affected = LearnerImport::query()->whereKey($import->id)->where('status', LearnerImport::PROCESSING)->update([
                'status' => LearnerImport::DONE,
                'passwords' => $done->getAttributes()['passwords'] ?? null,
                'payload' => null,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            if ($affected !== 1) {
                throw new RuntimeException('Import déjà terminal.');
            }
            $this->audit->record(AuditAction::LearnerImportCompleted, $import, new: [
                'creees' => $import->created_count,
                'mises_a_jour' => $import->updated_count,
                'inchangees' => $import->unchanged_count,
                'rejetees' => $import->rejected_count,
            ], actor: $author);
        }, 1);
    }

    private function isDeadlock(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['40001', '1213'], true)
            || in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true);
    }
}
