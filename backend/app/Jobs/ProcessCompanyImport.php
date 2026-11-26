<?php

namespace App\Jobs;

use App\Services\Imports\CompanyImportRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Traitement d'un import d'entreprises (plan T-64 v1.3, § 6). Message = identifiant seul ; une
 * tentative ; même worker que l'import des apprenants (`database-imports`, file `imports`).
 */
class ProcessCompanyImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $importId) {}

    public function handle(CompanyImportRunner $runner): void
    {
        $runner->run($this->importId);
    }

    public function failed(?Throwable $exception): void
    {
        app(CompanyImportRunner::class)->markFailed($this->importId, CompanyImportRunner::ABANDONED_ERROR);
    }
}
