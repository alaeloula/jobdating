<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiels (ADM-09, T-02) : listes administrées par l'administrateur et l'équipe CME.
 *
 * - Aucune suppression (décision du 16/11/2026) : chaque valeur a un état « actif ».
 * - Unicités comparées selon la collation utf8mb4_unicode_ci (casse et accents ignorés).
 * - `seed_key` : clé technique des valeurs livrées par les données initiales (opportunités,
 *   étiquettes), réservée au serveur et immuable une fois attribuée.
 * - Noms de tables conformes au CDC 8.2 : `evaluation_tags_ref` pour le catalogue des étiquettes,
 *   `evaluation_tags` étant réservé à l'association évaluation ↔ étiquettes (T-31).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filieres', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('label', 150);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->foreignId('filiere_id')->constrained('filieres')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('campuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('city', 100)->nullable();
            $table->string('contact_email')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        foreach (['promotions', 'sectors', 'competences'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('label', 150)->unique();
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        Schema::create('opportunity_types', function (Blueprint $table) {
            $table->id();
            $table->string('label', 150)->unique();
            $table->unsignedInteger('position')->default(0);
            $table->string('seed_key', 100)->nullable()->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('evaluation_tags_ref', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('label', 150);
            $table->unsignedInteger('position')->default(0);
            $table->string('seed_key', 100)->nullable()->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'label']);
        });
    }

    public function down(): void
    {
        foreach (['evaluation_tags_ref', 'opportunity_types', 'competences', 'sectors', 'promotions', 'campuses', 'classes', 'filieres'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
