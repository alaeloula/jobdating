<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Dossier temporaire PRIVÉ et unique pour les fichiers intermédiaires d'OpenSpout (cache des chaînes
 * partagées à la lecture, feuille XML à l'écriture), supprimé dans un `finally` (plan T-13 v2, §1).
 * Après un arrêt brutal, `imports:cleanup` (toutes les 5 minutes) supprime les dossiers de plus de
 * 55 minutes (plan v2.1, C4).
 */
final class PrivateTemp
{
    public const MAX_AGE_MINUTES = 55;

    public static function root(): string
    {
        return storage_path('app/private/tmp');
    }

    /**
     * @template T
     *
     * @param  callable(string): T  $operation  reçoit le chemin du dossier
     * @return T
     */
    public static function run(callable $operation): mixed
    {
        $dir = self::root().DIRECTORY_SEPARATOR.Str::uuid()->toString();
        File::ensureDirectoryExists($dir, 0700);
        @chmod($dir, 0700);

        try {
            return $operation($dir);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** Supprime les dossiers abandonnés (arrêt brutal). Renvoie le nombre de dossiers supprimés. */
    public static function sweep(): int
    {
        if (! is_dir(self::root())) {
            return 0;
        }

        $limit = time() - self::MAX_AGE_MINUTES * 60;
        $deleted = 0;
        foreach (File::directories(self::root()) as $dir) {
            if ((int) filemtime($dir) < $limit) {
                File::deleteDirectory($dir);
                $deleted++;
            }
        }

        return $deleted;
    }
}
