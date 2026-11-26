<?php

use App\Support\CompanyNameKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Import des entreprises (ADM-04, T-64, plan v1.3).
 *
 * - `companies.name_key` : empreinte SHA-256 du nom simplifié complet ; indexée, NON unique (les
 *   homonymes restent permis à la main). Remplie ici pour les entreprises existantes.
 * - `company_name_locks` : une ligne unique, verrou commun des noms (import, création, renommage).
 * - `company_imports` : imports ; l'événement passe à NULL s'il est supprimé (décision 9 du 25/11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->char('name_key', 64)->nullable()->after('name')->index();
        });
        DB::table('companies')->orderBy('id')->select(['id', 'name'])->chunkById(500, function ($companies): void {
            foreach ($companies as $company) {
                DB::table('companies')->where('id', $company->id)->update(['name_key' => CompanyNameKey::of((string) $company->name)]);
            }
        });

        Schema::create('company_name_locks', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('company_name_locks')->insert(['id' => 1]);

        Schema::create('company_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->string('event_name', 180);
            $table->string('filename', 255);
            $table->uuid('confirmation_key')->unique();
            $table->string('fingerprint', 64);
            $table->string('status', 20)->index();
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('existing_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->unsignedInteger('unconfirmed_count')->default(0);
            // Lignes signalées : numéro, motif et nom seulement (liste blanche, plan § 8).
            $table->json('signaled')->nullable();
            $table->longText('payload')->nullable();
            $table->longText('credentials')->nullable();
            $table->timestamp('credentials_deleted_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_imports');
        Schema::dropIfExists('company_name_locks');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['name_key']);
            $table->dropColumn('name_key');
        });
    }
};
