<?php

namespace Tests\Feature\Passwords;

use App\Enums\AuditAction;
use App\Enums\PasswordTokenType;
use App\Enums\Role;
use App\Http\Middleware\EnsureSessionIsValid;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\SessionRevoker;
use App\Services\Passwords\PasswordTokenService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BrowserSessions;
use Tests\TestCase;

/**
 * Changement de son mot de passe depuis le profil (AUTH-03, RG-AUTH-11, RG-AUTH-12).
 */
class PasswordChangeTest extends TestCase
{
    use BrowserSessions, RefreshDatabase;

    private const NEW_PASSWORD = 'Kq7#mZ2vRt-nouveau';

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database']);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return array<string, string>
     */
    private function change(string $current = 'password', string $new = self::NEW_PASSWORD, ?string $confirmation = null): array
    {
        return ['current_password' => $current, 'password' => $new, 'password_confirmation' => $confirmation ?? $new];
    }

    private function identifier(User $user): string
    {
        return (string) ($user->username ?? $user->email);
    }

    /**
     * @return iterable<string, array{Role}>
     */
    public static function roles(): iterable
    {
        foreach (Role::cases() as $role) {
            yield $role->value => [$role];
        }
    }

    #[DataProvider('roles')]
    public function test_les_cinq_roles_changent_leur_mot_de_passe(Role $role): void
    {
        $user = User::factory()->withRole($role)->create();
        $browser = [];
        $this->browserRequest($browser, 'POST', '/api/v1/login', ['identifier' => $this->identifier($user), 'password' => 'password'])->assertOk();

        $this->browserRequest($browser, 'PUT', '/api/v1/me/password', $this->change())
            ->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $user->fresh()?->password));
        // Nouvelle requête authentifiée avec la session courante : toujours valable.
        $this->browserRequest($browser, 'GET', '/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_sans_session_401(): void
    {
        $browser = [];
        $this->browserRequest($browser, 'PUT', '/api/v1/me/password', $this->change())->assertUnauthorized();
    }

    /** RG-AUTH-12 pour les rôles autres qu'entreprise : l'autre session est fermée, la courante conservée. */
    public function test_autre_session_fermee_la_courante_conservee(): void
    {
        $cme = User::factory()->withRole(Role::Cme)->create(['email' => 'cme@example.test']);
        $first = [];
        $second = [];
        $credentials = ['identifier' => 'cme@example.test', 'password' => 'password'];
        $this->browserRequest($first, 'POST', '/api/v1/login', $credentials)->assertOk();
        $this->browserRequest($second, 'POST', '/api/v1/login', $credentials, '10.0.0.21')->assertOk();
        $before = DB::table('sessions')->where('user_id', $cme->id)->pluck('id')->all();

        $this->browserRequest($first, 'PUT', '/api/v1/me/password', $this->change())->assertNoContent();

        $this->browserRequest($first, 'GET', '/api/v1/me')->assertOk();
        $this->browserRequest($second, 'GET', '/api/v1/me')->assertUnauthorized();
        $after = DB::table('sessions')->where('user_id', $cme->id)->pluck('id')->all();
        $this->assertCount(1, $after);
        // Identifiant de la session courante renouvelé.
        $this->assertSame([], array_intersect($before, $after));

        $log = AuditLog::query()->where('action', AuditAction::PasswordChanged->value)->firstOrFail();
        $this->assertSame($cme->id, $log->user_id);
        $this->assertEquals(['note' => 'Autres sessions fermées'], $log->new_values);
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::AccountUpdated->value)->where('subject_id', $cme->id)->count());
    }

    /** Décision du 17/11/2026 : compte entreprise partagé, les autres recruteurs restent connectés. */
    public function test_compte_entreprise_les_autres_recruteurs_restent_connectes(): void
    {
        $company = User::factory()->withRole(Role::Entreprise)->create(['username' => 'entreprise-partagee']);
        $first = [];
        $second = [];
        $credentials = ['identifier' => 'entreprise-partagee', 'password' => 'password'];
        $this->browserRequest($first, 'POST', '/api/v1/login', $credentials)->assertOk();
        $this->browserRequest($second, 'POST', '/api/v1/login', $credentials, '10.0.0.21')->assertOk();
        $this->browserRequest($second, 'GET', '/api/v1/me')->assertOk();

        $this->browserRequest($first, 'PUT', '/api/v1/me/password', $this->change())->assertNoContent();

        // Les deux recruteurs continuent, plusieurs requêtes de suite (empreinte mise à jour).
        foreach ([1, 2] as $attempt) {
            $this->browserRequest($first, 'GET', '/api/v1/me')->assertOk();
            $this->browserRequest($second, 'GET', '/api/v1/me')->assertOk()->assertJsonPath('data.id', $company->id);
        }
        $this->assertSame(2, DB::table('sessions')->where('user_id', $company->id)->count());

        // Le nouveau mot de passe sert pour une nouvelle connexion, l'ancien non.
        $third = [];
        $this->browserRequest($third, 'POST', '/api/v1/login', $credentials, '10.0.0.22')->assertStatus(422);
        $this->browserRequest($third, 'POST', '/api/v1/login', ['identifier' => 'entreprise-partagee', 'password' => self::NEW_PASSWORD], '10.0.0.22')->assertOk();

        $log = AuditLog::query()->where('action', AuditAction::PasswordChanged->value)->firstOrFail();
        $this->assertEquals(['note' => 'Autres sessions conservées (compte entreprise)'], $log->new_values);
    }

    /**
     * Contre-revue Codex T-05, constat 6 : une session révoquée par une réinitialisation, puis
     * réécrite tardivement, ne redevient jamais valable lors d'un changement ultérieur.
     */
    public function test_entreprise_une_session_revoquee_ne_redevient_pas_valable(): void
    {
        $company = User::factory()->withRole(Role::Entreprise)->create(['username' => 'entreprise-partagee']);
        $intruder = [];
        $this->browserRequest($intruder, 'POST', '/api/v1/login', ['identifier' => 'entreprise-partagee', 'password' => 'password'])->assertOk();
        $staleRow = (array) DB::table('sessions')->where('user_id', $company->id)->first();

        // Réinitialisation (contrat de T-10 à T-12) : nouveau mot de passe et fermeture de toutes les sessions.
        $resetPassword = 'Reinitialise-2026!';
        DB::transaction(function () use ($company, $resetPassword): void {
            User::withoutAccountAudit(fn () => $company->forceFill(['password' => $resetPassword])->save());
            app(SessionRevoker::class)->closeAll($company);
        });
        // Une requête de l'ancien client, en cours pendant la réinitialisation, réécrit sa session.
        DB::table('sessions')->insert($staleRow);

        // Deux vrais recruteurs se connectent avec le nouveau mot de passe ; l'un le change ensuite.
        $recruiter = [];
        $colleague = [];
        $credentials = ['identifier' => 'entreprise-partagee', 'password' => $resetPassword];
        $this->browserRequest($recruiter, 'POST', '/api/v1/login', $credentials, '10.0.0.30')->assertOk();
        $this->browserRequest($colleague, 'POST', '/api/v1/login', $credentials, '10.0.0.31')->assertOk();
        $this->browserRequest($recruiter, 'PUT', '/api/v1/me/password', $this->change($resetPassword))->assertNoContent();

        // La session périmée est supprimée, jamais mise à jour.
        $this->assertDatabaseMissing('sessions', ['id' => $staleRow['id']]);

        // L'ancien client reste refusé ; le collègue reste connecté.
        $this->browserRequest($intruder, 'GET', '/api/v1/me')->assertUnauthorized();
        $this->browserRequest($intruder, 'GET', '/api/v1/me')->assertUnauthorized();
        $this->browserRequest($colleague, 'GET', '/api/v1/me')->assertOk();
        $this->browserRequest($recruiter, 'GET', '/api/v1/me')->assertOk();
    }

    /** La réinitialisation ferme toujours toutes les sessions, même d'une entreprise (décision du 17/11/2026). */
    public function test_la_fermeture_complete_reste_valable_pour_une_entreprise(): void
    {
        $company = User::factory()->withRole(Role::Entreprise)->create(['username' => 'entreprise-partagee']);
        $browser = [];
        $this->browserRequest($browser, 'POST', '/api/v1/login', ['identifier' => 'entreprise-partagee', 'password' => 'password'])->assertOk();

        app(SessionRevoker::class)->closeAll($company);

        $this->browserRequest($browser, 'GET', '/api/v1/me')->assertUnauthorized();
    }

    public function test_heure_de_connexion_conservee_limite_de_12_h_inchangee(): void
    {
        Carbon::setTestNow('2026-11-17 08:00:00');
        $user = User::factory()->withRole(Role::Apprenant)->create(['email' => 'eleve@example.test']);
        $browser = [];
        $this->browserRequest($browser, 'POST', '/api/v1/login', ['identifier' => 'eleve@example.test', 'password' => 'password'])->assertOk();

        Carbon::setTestNow('2026-11-17 19:00:00');
        $this->browserRequest($browser, 'PUT', '/api/v1/me/password', $this->change())->assertNoContent();
        $payload = unserialize(base64_decode((string) DB::table('sessions')->where('user_id', $user->id)->value('payload')));
        $this->assertSame(Carbon::parse('2026-11-17 08:00:00')->getTimestamp(), data_get($payload, EnsureSessionIsValid::LOGGED_IN_AT));

        // 12 h après la connexion (et non après le changement) : session expirée.
        Carbon::setTestNow('2026-11-17 20:00:00');
        $this->browserRequest($browser, 'GET', '/api/v1/me')->assertUnauthorized();
        Carbon::setTestNow();
    }

    public function test_mauvais_mot_de_passe_actuel_refuse(): void
    {
        $user = User::factory()->withRole(Role::Apprenant)->create(['email' => 'eleve@example.test']);
        $browser = [];
        $this->browserRequest($browser, 'POST', '/api/v1/login', ['identifier' => 'eleve@example.test', 'password' => 'password'])->assertOk();

        // Critère AUTH-03 : refusé sans le bon mot de passe actuel.
        $this->browserRequest($browser, 'PUT', '/api/v1/me/password', $this->change('faux-mot-de-passe'))
            ->assertStatus(422)->assertJsonPath('errors.current_password.0', 'Mot de passe actuel incorrect.');
        $this->browserRequest($browser, 'PUT', '/api/v1/me/password', $this->change('password', 'password'))
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->browserRequest($browser, 'PUT', '/api/v1/me/password', $this->change('password', self::NEW_PASSWORD, 'autre-chose-2026'))
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'Les deux mots de passe ne correspondent pas.');

        $this->assertTrue(Hash::check('password', (string) $user->fresh()?->password));
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::PasswordChanged->value)->count());
    }

    public function test_nouveau_identique_a_l_actuel_refuse(): void
    {
        $user = User::factory()->withRole(Role::Apprenant)->create(['email' => 'eleve@example.test']);
        $user->forceFill(['password' => self::NEW_PASSWORD])->save();
        $browser = [];
        $this->browserRequest($browser, 'POST', '/api/v1/login', ['identifier' => 'eleve@example.test', 'password' => self::NEW_PASSWORD])->assertOk();

        $this->browserRequest($browser, 'PUT', '/api/v1/me/password', $this->change(self::NEW_PASSWORD, self::NEW_PASSWORD))
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'Choisissez un mot de passe différent de l’actuel.');
    }

    public function test_les_liens_de_reinitialisation_en_attente_sont_annules(): void
    {
        $user = User::factory()->withRole(Role::Apprenant)->create(['email' => 'eleve@example.test']);
        app(PasswordTokenService::class)->issue($user, PasswordTokenType::Reset);
        $browser = [];
        $this->browserRequest($browser, 'POST', '/api/v1/login', ['identifier' => 'eleve@example.test', 'password' => 'password'])->assertOk();

        $this->browserRequest($browser, 'PUT', '/api/v1/me/password', $this->change())->assertNoContent();

        $this->assertSame(0, DB::table('password_tokens')->where('user_id', $user->id)->count());
    }

    /** Revue Codex T-05, constat 1 : une réinitialisation intercalée n'est jamais écrasée. */
    public function test_une_reinitialisation_intercalee_n_est_pas_ecrasee_avec_l_ancien_mot_de_passe(): void
    {
        config(['session.driver' => 'array']);
        $stale = User::factory()->withRole(Role::Apprenant)->create();

        // Entre le chargement de l'utilisateur et le changement, une réinitialisation aboutit.
        $resetPassword = 'Reinitialise-2026!';
        User::query()->findOrFail($stale->id)->forceFill(['password' => $resetPassword])->save();

        // La requête porte l'objet chargé AVANT la réinitialisation (ancien hash) et l'ancien mot de passe.
        $this->actingAs($stale, 'web')
            ->putJson('/api/v1/me/password', $this->change('password'), ['Origin' => 'http://localhost'])
            ->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'Mot de passe actuel incorrect.');

        $this->assertTrue(Hash::check($resetPassword, (string) $stale->fresh()?->password));
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::PasswordChanged->value)->count());
    }

    public function test_cinq_tentatives_par_minute(): void
    {
        User::factory()->withRole(Role::Apprenant)->create(['email' => 'eleve@example.test']);
        $browser = [];
        $this->browserRequest($browser, 'POST', '/api/v1/login', ['identifier' => 'eleve@example.test', 'password' => 'password'])->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->browserRequest($browser, 'PUT', '/api/v1/me/password', $this->change('faux-'.$i))->assertStatus(422);
        }
        $this->browserRequest($browser, 'PUT', '/api/v1/me/password', $this->change())
            ->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    }
}
