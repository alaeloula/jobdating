<?php

namespace App\Jobs;

use App\Services\Imports\LearnerImportRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Traitement d'un import d'apprenants (plan T-13 v2.1, §5). Le message ne contient QUE l'identifiant.
 * Une seule tentative ; délai de 600 s, appliqué par le worker dédié (pcntl) :
 * `php artisan queue:work database-imports --queue=imports --timeout=660` ; relivraison après 900 s.
 */
class ProcessLearnerImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $importId) {}

    public function handle(LearnerImportRunner $runner): void
    {
        $runner->run($this->importId);
    }

    /**
     * Échec signalé par le worker (délai, relivraison au-delà des tentatives) : seulement si l'import
     * n'est pas déjà terminé ou en échec (plan v2.1, C2).
     */
    public function failed(?Throwable $exception): void
    {
        app(LearnerImportRunner::class)->markFailed($this->importId, LearnerImportRunner::ABANDONED_ERROR);
    }
}
