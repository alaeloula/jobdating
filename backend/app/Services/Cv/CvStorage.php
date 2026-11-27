<?php

namespace App\Services\Cv;

use App\Models\CvDocument;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Fichiers des CV (plan T-20 v1.2, § 4) : disque privé `local`, dossier `cvs/`, nom aléatoire (ni nom
 * d'apprenant ni nom d'origine), contenu chiffré avec la clé de l'application. Le disque ayant
 * `throw = false`, chaque écriture et chaque suppression est VÉRIFIÉE (revue Codex, constat 2).
 */
class CvStorage
{
    public const DISK = 'local';

    public const DIR = 'cvs';

    /** Âge minimal d'un fichier non référencé avant suppression par le nettoyage. */
    public const ORPHAN_MINUTES = 60;

    /**
     * Écrit le PDF chiffré et renvoie son chemin ; exception si l'écriture n'est pas confirmée.
     *
     * Le verrou nommé du fichier est pris AVANT l'écriture et reste tenu par la connexion jusqu'à
     * `release()`, appelé par le service après la validation ou l'abandon : le nettoyage n'efface
     * jamais une préparation encore active (revue Codex T-20, constat 2). Si le processus s'arrête
     * brutalement, MySQL libère le verrou avec la connexion.
     */
    public function store(string $plain): string
    {
        // Disque ouvert AVANT la prise du verrou : son échec ne peut pas laisser un verrou détenu
        // (vérification Codex T-20, V1).
        $disk = Storage::disk(self::DISK);
        $path = self::DIR.'/'.Str::uuid()->toString().'.bin';
        if (! $this->lock($path, 10)) {
            throw new RuntimeException('Verrou du fichier de CV indisponible.');
        }
        // Toute erreur après la prise du verrou (chiffrement, disque) le libère et efface le fichier
        // partiel : le service n'a pas encore le chemin pour le faire (contre-revue Codex T-20, N4).
        try {
            $encrypted = Crypt::encryptString($plain);
            if (! $disk->put($path, $encrypted) || ! $disk->exists($path) || $disk->size($path) !== strlen($encrypted)) {
                throw new RuntimeException('Écriture du CV non confirmée.');
            }
        } catch (Throwable $e) {
            try {
                $disk->delete($path);
            } finally {
                $this->release($path);
            }
            throw $e instanceof RuntimeException ? $e : new RuntimeException('Préparation du CV impossible.', 0, $e);
        }

        return $path;
    }

    /** Libère le verrou nommé du fichier (fin de l'envoi, adopté ou abandonné). */
    public function release(string $path): void
    {
        DB::selectOne('select release_lock(?) as released', [self::lockName($path)]);
    }

    /**
     * Vrai si la connexion COURANTE détient toujours le verrou du fichier. Après une reconnexion, la
     * session d'origine et son verrou ont disparu : l'adoption doit alors être abandonnée (contre-revue
     * Codex T-20, N3).
     */
    public function ownsLock(string $path): bool
    {
        return (int) DB::selectOne('select coalesce(is_used_lock(?) = connection_id(), 0) as owned', [self::lockName($path)])?->owned === 1;
    }

    private function lock(string $path, int $seconds): bool
    {
        return (int) DB::selectOne('select get_lock(?, ?) as acquired', [self::lockName($path), $seconds])?->acquired === 1;
    }

    /** Nom du verrou (64 caractères au plus pour MySQL) : le nom aléatoire du fichier, jamais une donnée personnelle. */
    private static function lockName(string $path): string
    {
        return 'cv-file:'.basename($path, '.bin');
    }

    public function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }

    /** Contenu PDF en clair (T-21). Les anciennes clés de `APP_PREVIOUS_KEYS` restent utilisables. */
    public function read(string $path): string
    {
        $content = Storage::disk(self::DISK)->get($path);
        if ($content === null) {
            throw new RuntimeException('CV introuvable sur le disque.');
        }

        return Crypt::decryptString($content);
    }

    /** Vrai si le fichier n'existe plus après l'appel. */
    public function delete(string $path): bool
    {
        $disk = Storage::disk(self::DISK);
        $disk->delete($path);

        return ! $disk->exists($path);
    }

    /**
     * Supprime les fichiers de `cvs/` absents de `cv_documents` et plus vieux que `ORPHAN_MINUTES` :
     * préparations abandonnées après un arrêt brutal, anciens fichiers non supprimés. Un fichier
     * récent est conservé. Chaque candidat est traité SOUS son verrou nommé, pris sans attendre : une
     * préparation encore active (verrou tenu par l'envoi) est ignorée, et l'absence de référence est
     * relue sous ce verrou juste avant la suppression (revue Codex T-20, constat 2).
     */
    public function sweepOrphans(): int
    {
        $disk = Storage::disk(self::DISK);
        $limit = now()->subMinutes(self::ORPHAN_MINUTES)->getTimestamp();
        $deleted = 0;
        foreach (array_chunk($disk->files(self::DIR), 500) as $chunk) {
            $referenced = array_flip(CvDocument::query()->whereIn('path', $chunk)->pluck('path')->all());
            foreach ($chunk as $path) {
                if (isset($referenced[$path]) || $disk->lastModified($path) >= $limit || ! $this->lock($path, 0)) {
                    continue;
                }
                try {
                    if (! CvDocument::query()->where('path', $path)->exists() && $this->delete($path)) {
                        $deleted++;
                    }
                } finally {
                    $this->release($path);
                }
            }
        }

        return $deleted;
    }
}
