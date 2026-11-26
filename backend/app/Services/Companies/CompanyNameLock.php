<?php

namespace App\Services\Companies;

use Illuminate\Support\Facades\DB;

/**
 * Verrou commun des noms d'entreprise (plan T-64 v1.3, § 4) : une ligne unique de `company_name_locks`,
 * prise en mode exclusif et EN PREMIER par l'import des entreprises, la création et le renommage à la
 * main. Il garantit que la revalidation d'un import voit toute création ou tout renommage validé avant
 * lui. Jamais pris après le verrou d'un événement, d'une entreprise ou d'un compte.
 */
final class CompanyNameLock
{
    public static function acquire(): void
    {
        DB::table('company_name_locks')->where('id', 1)->lockForUpdate()->first();
    }
}
