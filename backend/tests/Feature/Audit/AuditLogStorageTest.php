<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * TRA-01 : journal non modifiable, données sensibles exclues, purge après un an
 * limitée au journal d'audit.
 */
class AuditLogStorageTest extends TestCase
{
    use RefreshDatabase;

    private function logger(): AuditLogger
    {
        return app(AuditLogger::class);
    }

    public function test_le_modele_refuse_modification_et_suppression(): void
    {
        $log = $this->logger()->record(AuditAction::AccountUpdated);

        try {
            $log->update(['actor_label' => 'falsifié']);
            $this->fail('La modification aurait dû être refusée.');
        } catch (LogicException) {
        }

        try {
            $log->delete();
            $this->fail('La suppression aurait dû être refusée.');
        } catch (LogicException) {
        }

        // Valeur d'origine conservée (« Système » : action hors requête web).
        $this->assertSame(AuditLogger::SYSTEM_LABEL, AuditLog::query()->find($log->id)?->actor_label);
    }

    public function test_mysql_refuse_une_modification_directe(): void
    {
        $log = $this->logger()->record(AuditAction::AccountUpdated);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Journal d\'audit non modifiable');

        DB::table('audit_logs')->where('id', $log->id)->update(['actor_label' => 'falsifié']);
    }

    public function test_mysql_refuse_la_suppression_d_une_ligne_recente(): void
    {
        $log = $this->logger()->record(AuditAction::AccountUpdated);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('seules les lignes de plus d\'un an');

        DB::table('audit_logs')->where('id', $log->id)->delete();
    }

    public function test_la_purge_ne_supprime_que_les_lignes_du_journal_de_plus_d_un_an(): void
    {
        $user = User::factory()->create();
        $usersBefore = User::query()->count();

        $old = AuditLog::query()->create([
            'action' => AuditAction::AccountUpdated, 'category' => AuditAction::AccountUpdated->category(),
            'created_at' => now()->subYear()->subDays(3),
        ]);
        $recent = AuditLog::query()->create([
            'action' => AuditAction::AccountUpdated, 'category' => AuditAction::AccountUpdated->category(),
            'created_at' => now()->subMonths(11),
        ]);

        $this->artisan('audit:purge')->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recent->id]);
        // Aucune autre donnée n'est touchée.
        $this->assertSame($usersBefore, User::query()->count());
        $this->assertNotNull($user->fresh());
    }

    public function test_les_secrets_ne_sont_jamais_enregistres_et_la_cnie_est_masquee(): void
    {
        $log = $this->logger()->record(
            AuditAction::AccountUpdated,
            old: ['password' => 'secret-fictif', 'remember_token' => 'abc', 'cnie' => 'ZZ123456'],
            new: ['email' => 'compte@example.test', 'nested' => ['api_token' => 'x', 'nom' => 'Fictif'], 'CNIE' => 'ZZ654321'],
        );

        $log->refresh();
        $this->assertEquals(['cnie' => '******56'], $log->old_values);
        $this->assertEquals(['email' => 'compte@example.test', 'nested' => ['nom' => 'Fictif'], 'CNIE' => '******21'], $log->new_values);
        $this->assertStringNotContainsString('secret-fictif', (string) json_encode(DB::table('audit_logs')->get()));
    }

    public function test_auteur_ip_et_element_sont_enregistres(): void
    {
        $actor = User::factory()->create(['first_name' => 'Admin', 'last_name' => 'Fictif', 'email' => 'admin@example.test']);
        $subject = User::factory()->create(['first_name' => 'Cible', 'last_name' => 'Fictive', 'username' => 'cible-fictive', 'email' => null]);
        $this->actingAs($actor, 'web');

        $log = $this->logger()->record(AuditAction::AccountUpdated, $subject);

        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame('Admin Fictif (admin@example.test)', $log->actor_label);
        $this->assertSame('user', $log->subject_type);
        $this->assertSame($subject->id, $log->subject_id);
        $this->assertSame('Cible Fictive (cible-fictive)', $log->subject_label);
        // Hors requête web, pas d'adresse IP (l'IP d'une requête est vérifiée dans AuditEventsTest).
        $this->assertNull($log->ip);
        $this->assertNotNull($log->created_at);
    }

    public function test_hors_requete_web_l_auteur_est_systeme(): void
    {
        $log = $this->logger()->record(AuditAction::AccountUpdated);

        $this->assertNull($log->user_id);
        $this->assertSame(AuditLogger::SYSTEM_LABEL, $log->actor_label);
        $this->assertNull($log->ip);
    }

    /**
     * Régression (revue Codex T-06, constat 1) : variantes usuelles de noms de mot de passe et
     * de secrets, à toute profondeur, en ignorant casse, accents et séparateurs.
     */
    public function test_les_variantes_de_mot_de_passe_sont_exclues(): void
    {
        $log = $this->logger()->record(AuditAction::AccountUpdated, new: [
            'pwd' => 'v1', 'PassWD' => 'v2', 'mot_de_passe' => 'v3', 'Mot-De-Passe' => 'v4', 'mdp' => 'v5',
            'pass' => 'v6', 'api-key' => 'v7', 'client_secret' => 'v8',
            'nested' => ['PASSWD' => 'v9', 'motDePasse' => 'v10', 'deep' => ['Password_Confirmation' => 'v11']],
            'passage' => 'conservé', 'email' => 'compte@example.test',
        ]);

        $log->refresh();
        $this->assertEquals(
            ['nested' => ['deep' => []], 'passage' => 'conservé', 'email' => 'compte@example.test'],
            $log->new_values,
        );
        $raw = (string) json_encode(DB::table('audit_logs')->where('id', $log->id)->get());
        foreach (['v1', 'v2', 'v3', 'v4', 'v5', 'v6', 'v7', 'v8', 'v9', 'v10', 'v11'] as $secret) {
            $this->assertStringNotContainsString("\"{$secret}\"", $raw);
        }
    }

    /**
     * Régression (revue Codex T-06, constat 4) : le déclencheur applique exactement un an ;
     * la purge applicative attend un an et un jour.
     */
    public function test_seuil_exact_d_un_an_et_purge_a_un_an_et_un_jour(): void
    {
        $insert = fn (string $sqlDate) => DB::table('audit_logs')->insertGetId([
            'action' => AuditAction::AccountUpdated->value, 'category' => 'comptes', 'created_at' => DB::raw($sqlDate),
        ]);

        $youngerThanOneYear = $insert('NOW() - INTERVAL 1 YEAR + INTERVAL 12 HOUR');
        $olderThanOneYear = $insert('NOW() - INTERVAL 1 YEAR - INTERVAL 12 HOUR');
        $olderThanOneYearAndOneDay = $insert('NOW() - INTERVAL 1 YEAR - INTERVAL 2 DAY');

        // Moins d'un an, même de quelques heures : suppression directe refusée par MySQL.
        try {
            DB::table('audit_logs')->where('id', $youngerThanOneYear)->delete();
            $this->fail('La suppression d’une ligne de moins d’un an aurait dû être refusée.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('seules les lignes de plus d\'un an', $e->getMessage());
        }

        // La purge ne supprime que ce qui dépasse un an et un jour.
        $this->assertSame(1, $this->logger()->purge());
        $this->assertDatabaseHas('audit_logs', ['id' => $youngerThanOneYear]);
        $this->assertDatabaseHas('audit_logs', ['id' => $olderThanOneYear]);
        $this->assertDatabaseMissing('audit_logs', ['id' => $olderThanOneYearAndOneDay]);
    }
}
