<?php

use App\Services\AuditLogger;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Purge annuelle du journal d'audit (EXI-DATA-02) : supprime uniquement les lignes du journal
// d'audit de plus d'un an. Aucune autre donnée de la base n'est touchée.
Artisan::command('audit:purge', function (AuditLogger $audit) {
    $count = $audit->purge();
    $this->info("Journal d'audit : {$count} ligne(s) de plus d'un an supprimée(s).");
})->purpose("Supprime les lignes du journal d'audit de plus d'un an (et rien d'autre)");

Schedule::command('audit:purge')->dailyAt('03:00')->withoutOverlapping();
