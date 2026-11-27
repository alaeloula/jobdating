<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Numéro de version du CV (plan T-21 v1.2, décision 9) : UUID renouvelé à chaque ajout ou remplacement
 * effectif ; un lien de consultation ne vaut que pour sa version. Trois étapes : colonne nullable,
 * un UUID différent par ligne existante, puis NOT NULL et unicité (aucune valeur par défaut commune).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cv_documents', function (Blueprint $table) {
            $table->char('version', 36)->nullable()->after('learner_id');
        });

        DB::table('cv_documents')->whereNull('version')->orderBy('id')->select('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('cv_documents')->where('id', $row->id)->update(['version' => (string) Str::uuid()]);
            }
        });

        Schema::table('cv_documents', function (Blueprint $table) {
            $table->char('version', 36)->nullable(false)->change();
            $table->unique('version');
        });
    }

    public function down(): void
    {
        Schema::table('cv_documents', function (Blueprint $table) {
            $table->dropUnique(['version']);
            $table->dropColumn('version');
        });
    }
};
