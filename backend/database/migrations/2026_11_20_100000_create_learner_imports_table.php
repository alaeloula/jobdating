<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imports du fichier YouCode School Admin (ADM-04, T-13). `payload` (lignes utiles pendant le
 * traitement) et `passwords` (fichier des mots de passe) sont CHIFFRÉS ; `payload` est effacé à la
 * fin du traitement, succès ou échec (décision 10 du 20/11/2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('campus_id')->constrained('campuses')->restrictOnDelete();
            $table->foreignId('promotion_id')->constrained('promotions')->restrictOnDelete();
            $table->string('filename', 255);
            $table->uuid('confirmation_key')->unique();
            $table->string('fingerprint', 64);
            $table->string('status', 20)->index();
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->json('class_counts')->nullable(); // comptes prévus par classe (v2.2 : classe lue sur chaque ligne)
            $table->json('rejections')->nullable();
            $table->longText('payload')->nullable();
            $table->longText('passwords')->nullable();
            $table->timestamp('passwords_deleted_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_imports');
    }
};
