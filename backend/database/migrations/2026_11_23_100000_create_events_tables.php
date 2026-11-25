<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Événements et participants (ADM-05, ADM-07, T-14).
 *
 * - `current_marker` vaut 1 pour l'événement courant et NULL sinon : l'index unique garantit dans la
 *   base « au plus un événement courant » (RG-EVT-01).
 * - `school_year` est unique, événements archivés compris (décision du 23/11) ; `name` ne la contient pas.
 * - La colonne `statut` du modèle 8.2 n'est pas créée : le CDC n'en définit aucune valeur.
 * - `event_locks` contient une seule ligne, sans donnée métier : c'est le verrou commun aux
 *   inscriptions automatiques (plan T-14, § 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->char('school_year', 9)->unique();
            $table->date('date');
            $table->string('location', 255);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_current')->default(false);
            $table->tinyInteger('current_marker')->nullable()->virtualAs('IF(is_current, 1, NULL)');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique('current_marker');
        });

        Schema::create('event_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('floor', 50)->nullable();
            $table->string('table_label', 50)->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'company_id']);
        });

        Schema::create('event_learners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained('learners')->restrictOnDelete();
            $table->timestamp('enrolled_at');
            // Un retrait garde la ligne : ni un import ni une réactivation ne l'annulent (décision 1).
            $table->timestamp('removed_at')->nullable();

            $table->unique(['event_id', 'learner_id']);
        });

        Schema::create('event_locks', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('event_locks')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('event_locks');
        Schema::dropIfExists('event_learners');
        Schema::dropIfExists('event_companies');
        Schema::dropIfExists('events');
    }
};
