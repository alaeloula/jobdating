<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entreprises (ADM-02, RG-ENTR-01 à 08, T-11).
 *
 * - Le compte de connexion reste dans `users` (rôle entreprise, nom d'utilisateur) : une fiche par compte.
 * - Profils recherchés : filières et compétences (RG-ENTR-08, décisions du 16/11 et du 19/11).
 * - Recruteurs : remplis par T-31 (évolution 1 V2), en consultation ici.
 * - Pas d'événement ni d'emplacement : T-14 (`event_companies`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('name', 150)->index();
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->string('website')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('contact_name', 150)->nullable();
            $table->string('contact_email')->nullable();
            // Version des identifiants remis (fiche de connexion) : incrémentée à chaque mot de passe généré.
            $table->unsignedInteger('credentials_version')->default(0);
            $table->timestamp('credentials_printed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('company_filiere', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('filiere_id')->constrained('filieres')->restrictOnDelete();
            $table->primary(['company_id', 'filiere_id']);
        });

        Schema::create('company_competence', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('competence_id')->constrained('competences')->restrictOnDelete();
            $table->primary(['company_id', 'competence_id']);
        });

        Schema::create('company_recruiters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('email');
            $table->string('phone', 30);
            $table->string('job_title', 150)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_recruiters');
        Schema::dropIfExists('company_competence');
        Schema::dropIfExists('company_filiere');
        Schema::dropIfExists('companies');
    }
};
