<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liens à usage unique (T-05) : activation (AUTH-02) et réinitialisation (AUTH-03).
 *
 * - Seul le SHA-256 du jeton est stocké : le jeton en clair n'existe que dans l'e-mail.
 * - Un seul lien valable par compte et par type : unicité (user_id, type).
 * - La table Laravel par défaut password_reset_tokens n'est pas utilisée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_tokens');
    }
};
