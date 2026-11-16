<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Données initiales, valables aussi en production : aucun compte de test
     * ni mot de passe par défaut (EXI-SEC-11, RG-AUTH-05).
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        // Opportunités et étiquettes validées (PC-23) ; aucune autre liste (décision du 16/11/2026).
        $this->call(ReferentialsSeeder::class);
    }
}
