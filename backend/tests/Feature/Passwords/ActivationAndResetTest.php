<?php

namespace Tests\Feature\Passwords;

use App\Enums\AuditAction;
use App\Enums\PasswordTokenType;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Passwords\PasswordTokenService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\Concerns\BrowserSessions;
use Tests\TestCase;

/**
 * Activation (AUTH-02) et réinitialisation par lien (AUTH-03), avec de vraies sessions en base.
 */
class ActivationAndResetTest extends TestCase
{
    use BrowserSessions, RefreshDatabase;

    private const NEW_PASSWORD = 'Kq7#mZ2vRt-nouveau';

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database']);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function token(User $user, PasswordTokenType $type): string
    {
        return (string) app(PasswordTokenService::class)->issue($user, $type);
    }

    /**
     * @return array<string, string>
     */
    private function newPassword(string $token, string $password = self::NEW_PASSWORD): array
    {
        return ['token' => $token, 'password' => $password, 'password_confirmation' => $password];
    }

    // ---------------------------------------------------------------- Activation

    public function test_un_compte_non_active_ne_peut_pas_se_connecter_avant_activation(): void
    {
        $cme = User::factory()->unactivated()->withRole(Role::Cme)->create(['email' => 'cme@example.test']);
        $browser = [];

        $this->browserRequest($browser, 'POST', '/api/v1/login', ['identifier' => 'cme@example.test', 'password' => 'password'])
            ->assertStatus(403)->assertJsonPath('code', 'account_not_activated');
        $this->assertNull($cme->fresh()?->activated_at);
    }

    public function test_activation_ouvre_la_session_et_le_lien_ne_sert_qu_une_fois(): void
    {
        $cme = User::factory()->unactivated()->withRole(Role::Cme)->create(['first_name' => 'Samira', 'email' => 'cme@example.test']);
        $token = $this->token($cme, PasswordTokenType::Activation);
        $browser = [];

        $this->browserRequest($browser, 'POST', '/api/v1/activation/check', ['token' => $token])
            ->assertOk()->assertExactJson(['data' => ['first_name' => 'Samira']])
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->browserRequest($browser, 'POST', '/api/v1/activation', $this->newPassword($token))
            ->assertOk()->assertJsonPath('data.id', $cme->id)->assertJsonPath('data.home', '/pilotage');

        $fresh = $cme->fresh();
        $this->assertNotNull($fresh?->activated_at);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password));
        $this->browserRequest($browser, 'GET', '/api/v1/me')->assertOk()->assertJsonPath('data.id', $cme->id);

        // Usage unique (critère AUTH-02).
        $other = [];
        $this->browserRequest($other, 'POST', '/api/v1/activation', $this->newPassword($token, 'Autre-mot-2026!'))
            ->assertStatus(410)->assertJsonPath('code', 'link_invalid');
        $this->browserRequest($other, 'POST', '/api/v1/activation/check', ['token' => $token])->assertStatus(410);

        // Journal : activation et connexion, distinctes ; aucune ligne « Compte modifié » en double.
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::AccountActivated->value)->where('user_id', $cme->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::LoginSucceeded->value)->where('user_id', $cme->id)->count());
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::AccountUpdated->value)->where('subject_id', $cme->id)->count());
        $this->assertFalse(User::accountAuditMuted());
    }

    public function test_activation_mot_de_passe_non_conforme_le_lien_reste_utilisable(): void
    {
        $cme = User::factory()->unactivated()->withRole(Role::Direction)->create();
        $token = $this->token($cme, PasswordTokenType::Activation);
        $browser = [];

        $this->browserRequest($browser, 'POST', '/api/v1/activation', $this->newPassword($token, 'court'))
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->browserRequest($browser, 'POST', '/api/v1/activation', ['token' => $token, 'password' => self::NEW_PASSWORD, 'password_confirmation' => 'different-2026!'])
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'Les deux mots de passe ne correspondent pas.');

        $this->assertNull($cme->fresh()?->activated_at);
        $this->browserRequest($browser, 'POST', '/api/v1/activation', $this->newPassword($token))->assertOk();
    }

    public function test_activation_lien_mal_forme_ou_inconnu_410(): void
    {
        $browser = [];
        foreach (['court', str_repeat('Z', 64), ''] as $token) {
            $response = $this->browserRequest($browser, 'POST', '/api/v1/activation/check', ['token' => $token]);
            $this->assertSame(410, $response->status());
        }
    }

    public function test_activation_avec_un_autre_compte_connecte_le_deconnecte_et_ne_le_cite_jamais(): void
    {
        User::factory()->withRole(Role::Apprenant)->create(['email' => 'b@example.test']);
        $cme = User::factory()->unactivated()->withRole(Role::Cme)->create();
        $token = $this->token($cme, PasswordTokenType::Activation);
        $browser = [];

        $this->browserRequest($browser, 'POST', '/api/v1/login', ['identifier' => 'b@example.test', 'password' => 'password'])->assertOk();
        $bId = User::query()->where('email', 'b@example.test')->value('id');

        $this->browserRequest($browser, 'POST', '/api/v1/activation', $this->newPassword($token))->assertOk()->assertJsonPath('data.id', $cme->id);

        $this->browserRequest($browser, 'GET', '/api/v1/me')->assertOk()->assertJsonPath('data.id', $cme->id);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $bId)->count());
        $this->assertSame($cme->id, AuditLog::query()->where('action', AuditAction::AccountActivated->value)->value('user_id'));
    }

    /** Revue Codex T-05, constat 3 : un échec du journal de connexion annule toute l'activation. */
    public function test_echec_du_journal_de_connexion_annule_l_activation_et_garde_le_lien(): void
    {
        $cme = User::factory()->unactivated()->withRole(Role::Cme)->create();
        $token = $this->token($cme, PasswordTokenType::Activation);
        // Seul le SECOND journal (connexion automatique) échoue ; lié avant la première requête.
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            /**
             * @param  array<string, mixed>  $old
             * @param  array<string, mixed>  $new
             */
            public function record(AuditAction $action, ?Model $subject = null, array $old = [], array $new = [], ?User $actor = null, bool $visitor = false): AuditLog
            {
                if ($action === AuditAction::LoginSucceeded) {
                    throw new RuntimeException('journal indisponible');
                }

                return parent::record($action, $subject, $old, $new, $actor, $visitor);
            }
        });
        $browser = [];

        $this->browserRequest($browser, 'POST', '/api/v1/activation', $this->newPassword($token))->assertStatus(500);

        $fresh = $cme->fresh();
        $this->assertNull($fresh?->activated_at);
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, (string) $fresh->password));
        $this->assertNull($fresh->last_login_at);
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::AccountActivated->value)->count());
        // Le lien reste utilisable et aucune session n'est ouverte.
        $this->assertSame(1, DB::table('password_tokens')->where('user_id', $cme->id)->count());
        $this->assertSame($cme->id, app(PasswordTokenService::class)->check($token, PasswordTokenType::Activation)->id);
        $this->browserRequest($browser, 'GET', '/api/v1/me')->assertUnauthorized();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $cme->id)->count());
    }

    // ---------------------------------------------------------------- Réinitialisation

    public function test_reinitialisation_ferme_toutes_les_sessions_sans_connexion_automatique(): void
    {
        $user = User::factory()->withRole(Role::Apprenant)->create(['email' => 'eleve@example.test']);
        $first = [];
        $second = [];
        $this->browserRequest($first, 'POST', '/api/v1/login', ['identifier' => 'eleve@example.test', 'password' => 'password'])->assertOk();
        $this->browserRequest($second, 'POST', '/api/v1/login', ['identifier' => 'eleve@example.test', 'password' => 'password'], '10.0.0.21')->assertOk();
        $rememberBefore = $user->fresh()?->remember_token;

        $token = $this->token($user, PasswordTokenType::Reset);
        $visitor = [];
        $this->browserRequest($visitor, 'POST', '/api/v1/password/reset/check', ['token' => $token])->assertOk();
        $this->browserRequest($visitor, 'POST', '/api/v1/password/reset', $this->newPassword($token))
            ->assertOk()->assertJsonPath('message', 'Mot de passe modifié. Vous pouvez vous connecter.');

        // Critère AUTH-03 : les sessions précédentes ne sont plus valides.
        $this->browserRequest($first, 'GET', '/api/v1/me')->assertUnauthorized();
        $this->browserRequest($second, 'GET', '/api/v1/me')->assertUnauthorized();
        $this->browserRequest($visitor, 'GET', '/api/v1/me')->assertUnauthorized();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertNotSame($rememberBefore, $user->fresh()?->remember_token);

        $this->browserRequest($visitor, 'POST', '/api/v1/login', ['identifier' => 'eleve@example.test', 'password' => 'password'])->assertStatus(422);
        $this->browserRequest($visitor, 'POST', '/api/v1/login', ['identifier' => 'eleve@example.test', 'password' => self::NEW_PASSWORD])->assertOk();

        $log = AuditLog::query()->where('action', AuditAction::PasswordReset->value)->firstOrFail();
        $this->assertSame($user->id, $log->user_id);
        $this->assertEquals(['note' => 'Sessions fermées'], $log->new_values);
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::AccountUpdated->value)->where('subject_id', $user->id)->count());
    }

    public function test_une_session_reecrite_apres_coup_reste_refusee(): void
    {
        $user = User::factory()->withRole(Role::Apprenant)->create(['email' => 'eleve@example.test']);
        $browser = [];
        $this->browserRequest($browser, 'POST', '/api/v1/login', ['identifier' => 'eleve@example.test', 'password' => 'password'])->assertOk();
        $row = (array) DB::table('sessions')->where('user_id', $user->id)->first();

        $token = $this->token($user, PasswordTokenType::Reset);
        $visitor = [];
        $this->browserRequest($visitor, 'POST', '/api/v1/password/reset', $this->newPassword($token))->assertOk();

        // Une requête en cours réécrit l'ancienne session (ancienne empreinte du mot de passe).
        DB::table('sessions')->insert($row);

        $this->browserRequest($browser, 'GET', '/api/v1/me')->assertUnauthorized();
    }

    public function test_reinitialisation_identique_a_l_actuel_refusee_et_lien_conserve(): void
    {
        $user = User::factory()->withRole(Role::Apprenant)->create();
        $user->forceFill(['password' => self::NEW_PASSWORD])->save();
        $token = $this->token($user, PasswordTokenType::Reset);
        $browser = [];

        $this->browserRequest($browser, 'POST', '/api/v1/password/reset', $this->newPassword($token))
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'Choisissez un mot de passe différent de l’actuel.');

        $this->browserRequest($browser, 'POST', '/api/v1/password/reset/check', ['token' => $token])->assertOk();
        $this->browserRequest($browser, 'POST', '/api/v1/password/reset', $this->newPassword($token, 'Encore-un-autre-2026'))->assertOk();
    }

    public function test_reinitialisation_avec_un_autre_compte_connecte(): void
    {
        User::factory()->withRole(Role::Cme)->create(['email' => 'b@example.test']);
        $user = User::factory()->withRole(Role::Apprenant)->create();
        $token = $this->token($user, PasswordTokenType::Reset);
        $browser = [];
        $this->browserRequest($browser, 'POST', '/api/v1/login', ['identifier' => 'b@example.test', 'password' => 'password'])->assertOk();

        $this->browserRequest($browser, 'POST', '/api/v1/password/reset', $this->newPassword($token))->assertOk();

        $this->browserRequest($browser, 'GET', '/api/v1/me')->assertUnauthorized();
        $this->assertSame($user->id, AuditLog::query()->where('action', AuditAction::PasswordReset->value)->value('user_id'));
    }

    public function test_jeton_d_activation_refuse_sur_la_reinitialisation(): void
    {
        $cme = User::factory()->unactivated()->withRole(Role::Cme)->create();
        $token = $this->token($cme, PasswordTokenType::Activation);
        $browser = [];

        $this->browserRequest($browser, 'POST', '/api/v1/password/reset', $this->newPassword($token))->assertStatus(410);
        $this->assertNull($cme->fresh()?->activated_at);
    }

    public function test_limite_de_dix_par_minute_sur_les_liens(): void
    {
        $browser = [];
        for ($i = 0; $i < 10; $i++) {
            $this->browserRequest($browser, 'POST', '/api/v1/password/reset/check', ['token' => str_repeat('a', 64)], '10.9.0.1')->assertStatus(410);
        }
        $this->browserRequest($browser, 'POST', '/api/v1/password/reset/check', ['token' => str_repeat('a', 64)], '10.9.0.1')
            ->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    }
}
