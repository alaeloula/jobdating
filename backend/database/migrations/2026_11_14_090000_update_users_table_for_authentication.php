<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table users alignée sur le CDC 8.2 (T-04).
 *
 * - prénom et nom à la place de name ;
 * - nom d'utilisateur unique (entreprises) ; e-mail unique mais facultatif ;
 * - activé_le, dernière_connexion, désactivé_le, supprimé_le.
 *
 * email_verified_at est retiré : l'activation du compte (activated_at, AUTH-02) le remplace.
 * La photo (CDC 8.2) sera ajoutée avec le profil.
 *
 * Hypothèse validée (16/11/2026) : la table ne contient aucun compte réel à cette étape
 * (aucun compte créé par les données initiales, EXI-SEC-11 ; base de développement jetable).
 * L'ancien `name` n'est donc pas repris : un compte antérieur aurait un prénom et un nom vides
 * et ne serait pas activé. Les données réelles arrivent ensuite par les imports et la reprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['name', 'email_verified_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 100)->after('id');
            $table->string('last_name', 100)->after('first_name');
            $table->string('username', 100)->nullable()->unique()->after('last_name');
            $table->string('email')->nullable()->change();
            $table->string('phone', 30)->nullable()->after('email');
            $table->timestamp('activated_at')->nullable()->after('password');
            $table->timestamp('last_login_at')->nullable()->after('activated_at');
            $table->timestamp('deactivated_at')->nullable()->after('last_login_at');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        // L'ancienne structure exige un e-mail. Les modifications de structure MySQL ne sont pas
        // annulables : on vérifie AVANT toute modification, pour ne jamais laisser la table à moitié
        // revenue en arrière (comptes entreprise sans e-mail, valides depuis T-04).
        $withoutEmail = DB::table('users')->whereNull('email')->count();

        if ($withoutEmail > 0) {
            throw new RuntimeException(
                "Annulation impossible : {$withoutEmail} compte(s) sans e-mail (entreprises). "
                .'L\'ancienne structure exige un e-mail. Aucune modification n\'a été faite.'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropUnique(['username']);
            $table->dropColumn([
                'first_name', 'last_name', 'username', 'phone',
                'activated_at', 'last_login_at', 'deactivated_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->after('id')->default('');
            $table->string('email')->nullable(false)->change();
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });
    }
};
