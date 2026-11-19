<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apprenants (ADM-03, T-12). Identité et connexion dans `users` (e-mail professionnel, téléphone) ;
 * la filière n'est pas stockée : c'est celle de la classe (RG-APR-01). Compétences sans niveau
 * (décision du 16/11/2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            // Normalisée (App\Support\Cnie) : lettres majuscules et chiffres, 5 à 30 caractères.
            $table->string('cnie', 30)->unique();
            $table->foreignId('campus_id')->constrained('campuses')->restrictOnDelete();
            $table->foreignId('promotion_id')->constrained('promotions')->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            // RG-APR-02 : unique quand il est renseigné (plusieurs NULL permis). Format lié à PC-26.
            $table->string('cme_learner_id', 100)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('learner_competence', function (Blueprint $table) {
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('competence_id')->constrained('competences')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['learner_id', 'competence_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_competence');
        Schema::dropIfExists('learners');
    }
};
