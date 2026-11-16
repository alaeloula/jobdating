<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Journal d'audit (TRA-01, T-06) : table en ajout seul.
 *
 * - Auteur et élément concerné sont recopiés en texte (actor_label, subject_label) pour rester
 *   lisibles même si le compte ou l'élément est supprimé ensuite.
 * - Pas de clé étrangère vers users : une suppression de compte ne doit jamais modifier le journal.
 * - Déclencheurs MySQL : toute modification est refusée ; une suppression n'est acceptée que pour
 *   les lignes de plus d'un an exactement (purge, EXI-DATA-02). La purge applicative attend
 *   un an et un jour : conservation d'au moins un an, et pas de refus dû au fuseau.
 * - Ces protections ne couvrent pas TRUNCATE ni DROP (DDL) : en production, l'application doit
 *   utiliser un compte MySQL sans droit DROP, distinct du compte des migrations (T-01).
 *
 * ATTENTION : down() supprime le journal et ses déclencheurs. Ne jamais l'exécuter en production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('actor_label')->nullable();
            $table->string('action', 100);
            $table->string('category', 30);
            $table->string('subject_type', 50)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['category', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_no_update
            BEFORE UPDATE ON audit_logs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal d''audit non modifiable (TRA-01).'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_purge_only
            BEFORE DELETE ON audit_logs FOR EACH ROW
            BEGIN
                IF OLD.created_at >= NOW() - INTERVAL 1 YEAR THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal d''audit : seules les lignes de plus d''un an peuvent être purgées.';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_purge_only');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
        Schema::dropIfExists('audit_logs');
    }
};
