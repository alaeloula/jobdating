<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CV des apprenants (T-20, plan v1.2 § 3) : un seul CV courant par apprenant (CDC 8.7) et
 * historique des ajouts (CV-04), commun aux téléversements, retraits et imports de T-22
 * (décision 8 : `cv_history` remplace `cv_import_logs`). L'historique est une donnée métier,
 * jamais purgée avec les journaux techniques (décision 11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cv_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->unique()->constrained('learners')->restrictOnDelete();
            $table->string('source', 20);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('added_by_name', 255)->nullable();
            $table->timestamp('added_at');
            $table->string('path', 255)->unique();
            $table->char('sha256', 64);
            $table->unsignedInteger('size');
            $table->string('mime', 100);
            $table->string('cme_reference', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('cv_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->restrictOnDelete();
            $table->string('action', 20);
            $table->string('source', 20);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 255)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at');
            $table->index(['learner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cv_history');
        Schema::dropIfExists('cv_documents');
    }
};
