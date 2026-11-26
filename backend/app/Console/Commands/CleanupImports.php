<?php

namespace App\Console\Commands;

use App\Models\CompanyImport;
use App\Models\LearnerImport;
use App\Services\Imports\CompanyImportRunner;
use App\Services\Imports\LearnerImportRunner;
use App\Support\PrivateTemp;
use Illuminate\Console\Command;

/**
 * Toutes les 5 minutes (plan T-13 v2.1) : imports abandonnés passés en échec (« en attente » depuis
 * plus de 20 minutes après la confirmation, « en cours » depuis plus de 20 minutes après leur
 * démarrage), `payload` effacé ; dossiers temporaires privés de plus de 55 minutes supprimés.
 */
class CleanupImports extends Command
{
    protected $signature = 'imports:cleanup';

    protected $description = 'Nettoie les imports abandonnés et les dossiers temporaires privés';

    public const STALE_MINUTES = 20;

    public function handle(LearnerImportRunner $runner, CompanyImportRunner $companies): int
    {
        $limit = now()->subMinutes(self::STALE_MINUTES);
        // Même condition pour la sélection ET pour la mise à jour atomique : un import pris en charge
        // entre les deux (started_at récent) n'est pas annulé (revue Codex T-13, constat 6).
        $stale = function ($q) use ($limit): void {
            $q->where(fn ($p) => $p->where('status', LearnerImport::PENDING)->where('created_at', '<', $limit))
                ->orWhere(fn ($p) => $p->where('status', LearnerImport::PROCESSING)->where('started_at', '<', $limit));
        };
        $ids = LearnerImport::query()->where($stale)->pluck('id');

        foreach ($ids as $id) {
            $runner->markFailed((int) $id, LearnerImportRunner::ABANDONED_ERROR, $stale);
        }
        // Imports d'entreprises (T-64) : même règle, même condition atomique.
        $staleCompanies = function ($q) use ($limit): void {
            $q->where(fn ($p) => $p->where('status', CompanyImport::PENDING)->where('created_at', '<', $limit))
                ->orWhere(fn ($p) => $p->where('status', CompanyImport::PROCESSING)->where('started_at', '<', $limit));
        };
        $companyIds = CompanyImport::query()->where($staleCompanies)->pluck('id');
        foreach ($companyIds as $id) {
            $companies->markFailed((int) $id, CompanyImportRunner::ABANDONED_ERROR, $staleCompanies);
        }
        $folders = PrivateTemp::sweep();

        $count = $ids->count() + $companyIds->count();
        $this->info("Imports abandonnés : {$count} ; dossiers temporaires supprimés : {$folders}.");

        return self::SUCCESS;
    }
}
