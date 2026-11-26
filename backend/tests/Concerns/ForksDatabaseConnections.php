<?php

namespace Tests\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Concurrence sur MySQL à DEUX connexions réelles (T-14, contre-revue R1 ; réutilisé par T-64) : le
 * processus enfant exécute une opération pendant que le parent détient ses verrous. La preuve
 * d'attente vient d'InnoDB (`performance_schema.data_lock_waits`), jamais de la durée d'une requête.
 *
 * Les enfants doivent être lancés AVANT toute transaction du parent : un lancement rouvre sa connexion.
 */
trait ForksDatabaseConnections
{
    protected const WAIT_SECONDS = 30;

    /** Durée d'observation des contrôles négatifs : aucune attente de verrou ne doit apparaître. */
    protected const NEGATIVE_SECONDS = 1.5;

    /**
     * Lance `$child` dans un autre processus, avec sa propre connexion. Il attend le fichier de départ.
     *
     * @return array{pid: int, start: string, result: string, connection: string}
     */
    private function fork(Closure $child): array
    {
        $start = (string) tempnam(sys_get_temp_dir(), 't14-start');
        unlink($start);
        $result = (string) tempnam(sys_get_temp_dir(), 't14-result');
        $connection = (string) tempnam(sys_get_temp_dir(), 't14-connection');
        DB::disconnect();

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $status = 'ok';
            try {
                $deadline = microtime(true) + self::WAIT_SECONDS;
                while (! file_exists($start) && microtime(true) < $deadline) {
                    usleep(5000);
                }
                DB::purge();
                DB::reconnect();
                // Numéro de SA connexion MySQL : seule celle-ci est surveillée par le parent (contre-revue T-14, R1).
                file_put_contents($connection, (string) DB::selectOne('select connection_id() as id')->id);
                $child();
            } catch (Throwable $e) {
                $status = $e::class.': '.mb_substr($e->getMessage(), 0, 200);
            }
            file_put_contents($result, $status);
            // Arrêt immédiat : aucun nettoyage de PHPUnit dans l'enfant.
            posix_kill((int) getmypid(), SIGKILL);
        }
        DB::reconnect();

        return ['pid' => $pid, 'start' => $start, 'result' => $result, 'connection' => $connection];
    }

    /**
     * Démarre l'enfant et attend que SA connexion attende un verrou détenu par la connexion du parent
     * (revue T-14, constat 6 ; contre-revue, R1). La preuve vient d'InnoDB (`data_lock_waits`), pas de
     * la durée d'une requête : une requête lente ou une autre connexion ne sont jamais acceptées.
     *
     * @param  array{pid: int, start: string, result: string, connection: string}  $child
     */
    private function release(array $child): void
    {
        touch($child['start']);
        $childId = $this->childConnection($child);
        if ($childId === null || ! $this->waitsForLockOf($childId, $this->parentConnection(), self::WAIT_SECONDS)) {
            posix_kill($child['pid'], SIGKILL);
            pcntl_waitpid($child['pid'], $status);
            $this->fail('L’opération concurrente n’a jamais attendu un verrou du parent.');
        }
    }

    /** @param array{pid: int, start: string, result: string, connection: string} $child */
    private function childConnection(array $child): ?int
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $child['connection']);
            if (filesize($child['connection']) > 0) {
                return (int) file_get_contents($child['connection']);
            }
            usleep(5000);
        }

        return null;
    }

    private function parentConnection(): int
    {
        return (int) DB::selectOne('select connection_id() as id')->id;
    }

    /**
     * Vrai dès qu'InnoDB montre la connexion `$waiting` en attente d'un verrou détenu par `$holder`,
     * observée depuis une troisième connexion ; faux à l'expiration du délai.
     */
    private function waitsForLockOf(int $waiting, int $holder, float $seconds): bool
    {
        config(['database.connections.probe' => config('database.connections.'.DB::getDefaultConnection())]);
        $probe = DB::connection('probe');
        $deadline = microtime(true) + $seconds;
        try {
            while (microtime(true) < $deadline) {
                $found = $probe->table('performance_schema.data_lock_waits as w')
                    ->join('performance_schema.threads as r', 'r.THREAD_ID', '=', 'w.REQUESTING_THREAD_ID')
                    ->join('performance_schema.threads as b', 'b.THREAD_ID', '=', 'w.BLOCKING_THREAD_ID')
                    ->where('r.PROCESSLIST_ID', $waiting)
                    ->where('b.PROCESSLIST_ID', $holder)
                    ->exists();
                if ($found) {
                    return true;
                }
                usleep(10000);
            }

            return false;
        } finally {
            DB::purge('probe');
        }
    }

    /**
     * Démarre l'enfant et attend sa FIN alors que le parent garde ses verrous : il ne doit pas en dépendre.
     *
     * @param  array{pid: int, start: string, result: string, connection: string}  $child
     */
    private function releaseUntilDone(array $child): void
    {
        touch($child['start']);
        $deadline = microtime(true) + self::WAIT_SECONDS;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $child['result']);
            if (filesize($child['result']) > 0) {
                return;
            }
            usleep(10000);
        }
        posix_kill($child['pid'], SIGKILL);
        pcntl_waitpid($child['pid'], $status);
        $this->fail('L’opération concurrente aurait dû se terminer sans attendre.');
    }

    /** @param array{pid: int, start: string, result: string, connection: string} $child */
    private function finish(array $child): void
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;
        while (pcntl_waitpid($child['pid'], $status, WNOHANG) === 0) {
            if (microtime(true) >= $deadline) {
                posix_kill($child['pid'], SIGKILL);
                pcntl_waitpid($child['pid'], $status);
                $this->fail('L’opération concurrente ne s’est pas terminée.');
            }
            usleep(10000);
        }
        $this->assertSame('ok', (string) file_get_contents($child['result']));
        @unlink($child['start']);
        @unlink($child['result']);
        @unlink($child['connection']);
    }
}
