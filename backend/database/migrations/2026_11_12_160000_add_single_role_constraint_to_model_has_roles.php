<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un compte a un seul rôle (RG-ACC-06) : garanti par la base, y compris en cas
 * d'opérations concurrentes.
 */
return new class extends Migration
{
    private const INDEX = 'model_has_roles_un_role_par_compte';

    public function up(): void
    {
        Schema::table(config('permission.table_names.model_has_roles'), function (Blueprint $table) {
            $table->unique([config('permission.column_names.model_morph_key'), 'model_type'], self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table(config('permission.table_names.model_has_roles'), function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });
    }
};
