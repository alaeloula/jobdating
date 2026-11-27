<?php

namespace App\Services\Cv;

use App\Enums\AuditAction;
use App\Models\CvDocument;
use App\Models\CvHistoryEntry;
use App\Models\Learner;
use App\Models\User;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Téléversement, remplacement et retrait du CV (CV-01, CV-04, DECISIONS 26/11 ; plan T-20 v1.2, § 5).
 *
 * ORDRE DES VERROUS : compte (`users`, suppression logique incluse) → fiche (`learners`) →
 * `cv_documents`. L'état « supprimé » est relu sous le verrou du compte (revue Codex, constat 1).
 *
 * CYCLE DE VIE D'UN FICHIER (constat 2 ; contre-revue, N1) :
 * - préparé : écrit chiffré HORS transaction, écriture vérifiée ;
 * - adopté : référencé par `cv_documents` dans une transaction VALIDÉE ; jamais supprimé ensuite ici ;
 * - abandonné : à la fin définitive d'un échec, d'un « inchangé » ou d'un refus → supprimé par le
 *   `finally`, après la sortie de la transaction. Une seule tentative : un interblocage fait échouer
 *   l'opération (409 `retry`), aucune reprise ne peut adopter un fichier déjà supprimé. Juste avant
 *   l'adoption, sous les verrous, le fichier préparé doit toujours exister.
 *
 * Le service refuse d'être appelé dans une transaction ouverte : T-22 l'appellera hors transaction,
 * un apprenant à la fois.
 */
class CvService
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const INVALID_MESSAGE = 'Le CV doit être un fichier PDF valide de 5 Mo au plus.';

    public function __construct(private readonly CvStorage $storage, private readonly AuditLogger $audit) {}

    /**
     * @return array{status: 'created'|'replaced'|'unchanged', document: CvDocument}
     */
    public function upload(Learner $learner, UploadedFile $file, User $actor): array
    {
        $this->assertNoTransaction();
        $plain = $this->readValidPdf($file);
        $hash = hash('sha256', $plain);
        $size = strlen($plain);

        try {
            $path = $this->storage->store($plain);
        } catch (RuntimeException) {
            throw CvRuleException::notSaved();
        }
        unset($plain);

        $adopted = false;
        $oldPath = null;
        try {
            $result = $this->transaction(function () use ($learner, $actor, $path, $hash, $size, &$oldPath): array {
                $this->lockAccount($learner);
                Learner::query()->whereKey($learner->id)->lockForUpdate()->firstOrFail();
                $current = CvDocument::query()->where('learner_id', $learner->id)->lockForUpdate()->first();

                // Même fichier (décision 5) : rien ne change, ni historique ni audit.
                if ($current !== null && hash_equals($current->sha256, $hash)) {
                    return ['status' => 'unchanged', 'document' => $current];
                }
                // Le verrou nommé du fichier, tenu depuis la préparation, empêche le nettoyage de
                // l'effacer entre ce contrôle et la validation (revue Codex T-20, constat 2). Il doit
                // être encore détenu par CETTE connexion : une reconnexion l'a libéré (contre-revue, N3).
                if (! $this->storage->ownsLock($path) || ! $this->storage->exists($path)) {
                    throw CvRuleException::notSaved();
                }

                $name = self::authorName($actor);
                $values = [
                    'source' => CvDocument::SOURCE_UPLOAD,
                    'added_by' => $actor->id,
                    'added_by_name' => $name,
                    'added_at' => now(),
                    'path' => $path,
                    'sha256' => $hash,
                    'size' => $size,
                    'mime' => 'application/pdf',
                    'cme_reference' => null,
                    // Nouvelle version à chaque ajout ou remplacement effectif (plan T-21, décision 9).
                    'version' => (string) Str::uuid(),
                ];
                if ($current !== null) {
                    $oldPath = $current->path;
                    $current->forceFill($values)->save();
                    $document = $current;
                } else {
                    $document = CvDocument::query()->forceCreate(['learner_id' => $learner->id] + $values);
                }
                $action = $current !== null ? CvHistoryEntry::REPLACED : CvHistoryEntry::UPLOADED;
                $this->history($learner, $action, $actor, $name, $hash, $size);
                $this->audit->record(
                    $current !== null ? AuditAction::CvReplaced : AuditAction::CvUploaded,
                    $learner,
                    new: ['source' => 'Téléversé', 'taille' => $size],
                    actor: $actor,
                );

                return ['status' => $current !== null ? 'replaced' : 'created', 'document' => $document];
            });
            $adopted = $result['status'] !== 'unchanged';
        } finally {
            try {
                if (! $adopted && ! $this->storage->delete($path)) {
                    Log::warning('CV préparé non supprimé ; il sera repris par le nettoyage.', ['learner_id' => $learner->id]);
                }
            } finally {
                // Verrou nommé tenu depuis la préparation : libéré seulement après validation ou abandon.
                $this->storage->release($path);
            }
        }

        // Après validation : l'ancien fichier n'est plus référencé. Un échec ne touche jamais au nouveau.
        if ($oldPath !== null) {
            $this->deleteOld($oldPath, $learner);
        }

        return $result;
    }

    public function remove(Learner $learner, User $actor): void
    {
        $this->assertNoTransaction();
        $path = $this->transaction(function () use ($learner, $actor): string {
            $this->lockAccount($learner);
            Learner::query()->whereKey($learner->id)->lockForUpdate()->firstOrFail();
            $current = CvDocument::query()->where('learner_id', $learner->id)->lockForUpdate()->first();
            if ($current === null) {
                throw CvRuleException::noCv();
            }
            $path = $current->path;
            $current->delete();
            $this->history($learner, CvHistoryEntry::REMOVED, $actor, self::authorName($actor), null, null, $current->source);
            $this->audit->record(AuditAction::CvRemoved, $learner, old: ['source' => $this->sourceLabel($current->source)], actor: $actor);

            return $path;
        });

        $this->deleteOld($path, $learner);
    }

    /** Contrôles RG-CV-05 sur le fichier RÉELLEMENT reçu (taille physique, type réel, structure). */
    private function readValidPdf(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $size = $path === false ? false : filesize($path);
        if ($size === false || $size === 0 || $size > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => [self::INVALID_MESSAGE]]);
        }
        $plain = (string) file_get_contents($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($plain);
        if (strlen($plain) !== $size || $mime !== 'application/pdf' || PdfInspector::problem($plain) !== null) {
            throw ValidationException::withMessages(['file' => [self::INVALID_MESSAGE]]);
        }

        return $plain;
    }

    private function lockAccount(Learner $learner): void
    {
        $user = User::withTrashed()->whereKey($learner->user_id)->lockForUpdate()->firstOrFail();
        if ($user->trashed()) {
            throw CvRuleException::learnerDeleted();
        }
    }

    /**
     * Une seule tentative (contre-revue, N1) : interblocage et attente de verrou → 409 `retry`.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function transaction(Closure $callback): mixed
    {
        try {
            return DB::transaction(fn () => $callback(), 1);
        } catch (DeadlockException) {
            throw CvRuleException::retry();
        } catch (QueryException $e) {
            if (in_array($e->errorInfo[1] ?? null, [1205, 1213], true)) {
                throw CvRuleException::retry();
            }
            throw $e;
        }
    }

    /**
     * `cv.transaction_base` vaut 0 en production ; les tests en `RefreshDatabase` le placent au niveau
     * de leur propre transaction englobante.
     */
    private function assertNoTransaction(): void
    {
        if (DB::transactionLevel() > (int) config('cv.transaction_base', 0)) {
            throw new LogicException('CvService ne doit pas être appelé dans une transaction ouverte.');
        }
    }

    private function deleteOld(string $path, Learner $learner): void
    {
        try {
            $deleted = $this->storage->delete($path);
        } catch (Throwable) {
            $deleted = false;
        }
        if (! $deleted) {
            Log::warning('Ancien CV non supprimé ; il sera repris par le nettoyage.', ['learner_id' => $learner->id]);
        }
    }

    private function history(Learner $learner, string $action, User $actor, string $name, ?string $hash, ?int $size, string $source = CvDocument::SOURCE_UPLOAD): void
    {
        CvHistoryEntry::query()->forceCreate([
            'learner_id' => $learner->id,
            'action' => $action,
            'source' => $source,
            'user_id' => $actor->id,
            'user_name' => $name,
            'sha256' => $hash,
            'size' => $size,
            'created_at' => now(),
        ]);
    }

    /** Auteur affiché à l'équipe (prénom et nom, sans adresse e-mail). */
    private static function authorName(User $actor): string
    {
        return mb_substr(trim($actor->first_name.' '.$actor->last_name), 0, 255);
    }

    private function sourceLabel(string $source): string
    {
        return $source === CvDocument::SOURCE_CME ? 'Plateforme CME' : 'Téléversé';
    }
}
