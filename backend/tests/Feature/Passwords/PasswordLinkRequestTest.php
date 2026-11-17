<?php

namespace Tests\Feature\Passwords;

use App\Enums\AuditAction;
use App\Enums\LinkRequest;
use App\Enums\Role;
use App\Http\Controllers\Auth\PasswordLinkRequestController;
use App\Jobs\SendPasswordLink;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Demandes publiques de lien (RG-AUTH-09, RG-AUTH-10, décision du 17/11/2026) : même réponse et
 * même travail quel que soit le compte ; limites communes.
 */
class PasswordLinkRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withHeader('Origin', 'http://localhost');
    }

    /**
     * @return array<string, string>
     */
    private function accounts(): array
    {
        $make = fn (string $email, Role $role, array $state = []) => User::factory()
            ->state($state)->withRole($role)->create(['email' => $email]);

        $make('apprenant@example.test', Role::Apprenant);
        $make('desactive@example.test', Role::Apprenant, ['deactivated_at' => now()]);
        $make('nonactive@example.test', Role::Cme, ['activated_at' => null]);
        $make('supprime@example.test', Role::Direction)->delete();
        User::factory()->create(['email' => 'sansrole@example.test']);

        return [
            'connu' => 'apprenant@example.test',
            'inconnu' => 'inconnu@example.test',
            'désactivé' => 'desactive@example.test',
            'non activé' => 'nonactive@example.test',
            'supprimé' => 'supprime@example.test',
            'sans rôle' => 'sansrole@example.test',
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function endpoints(): iterable
    {
        yield 'mot de passe oublié' => ['/api/v1/password/forgot'];
        yield 'nouveau lien d’activation' => ['/api/v1/activation/resend'];
    }

    #[DataProvider('endpoints')]
    public function test_meme_reponse_meme_travail_et_aucune_lecture_des_comptes(string $url): void
    {
        $accounts = $this->accounts();
        Queue::fake();

        $bodies = [];
        foreach ($accounts as $case => $email) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->postJson($url, ['email' => $email], ['REMOTE_ADDR' => '10.1.0.'.count($bodies)]);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            $response->assertStatus(202)->assertHeader('Cache-Control', 'no-store, private');
            $bodies[$case] = $response->getContent();

            foreach ($queries as $query) {
                $this->assertStringNotContainsString('`users`', $query['query'], "Lecture des comptes pour : {$case}");
            }
        }

        $this->assertCount(1, array_unique($bodies));
        $this->assertSame(['message' => PasswordLinkRequestController::MESSAGE], json_decode((string) reset($bodies), true));
        Queue::assertPushed(SendPasswordLink::class, count($accounts));
    }

    public function test_adresse_invalide_422_sans_tache(): void
    {
        Queue::fake();

        $this->postJson('/api/v1/password/forgot', ['email' => 'pas une adresse'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/password/forgot', [])->assertStatus(422);
        $this->postJson('/api/v1/password/forgot', ['email' => ['x']])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_budget_commun_par_adresse_selon_la_collation_mysql(): void
    {
        Queue::fake();

        $this->postJson('/api/v1/password/forgot', ['email' => 'jose@example.test'], ['REMOTE_ADDR' => '10.2.0.1'])->assertStatus(202);

        // Variantes que MySQL juge identiques (casse, accents, espaces autour), autre IP, autre demande : bloquées.
        foreach (['JOSÉ@example.test', '  jose@example.test  '] as $variant) {
            $this->postJson('/api/v1/activation/resend', ['email' => $variant], ['REMOTE_ADDR' => '10.2.0.2'])
                ->assertStatus(429)
                ->assertJsonPath('code', 'too_many_requests');
        }

        // « a » latin et « а » cyrillique : différents pour MySQL, donc budgets distincts.
        $this->postJson('/api/v1/password/forgot', ['email' => 'a@example.test'], ['REMOTE_ADDR' => '10.2.0.3'])->assertStatus(202);
        $this->postJson('/api/v1/password/forgot', ['email' => 'а@example.test'], ['REMOTE_ADDR' => '10.2.0.4'])->assertStatus(202);

        Queue::assertPushed(SendPasswordLink::class, 3);
    }

    public function test_dix_demandes_par_minute_par_ip_reponse_identique(): void
    {
        $this->accounts();
        Queue::fake();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/password/forgot', ['email' => "personne{$i}@example.test"], ['REMOTE_ADDR' => '10.3.0.1'])->assertStatus(202);
        }

        $known = $this->postJson('/api/v1/password/forgot', ['email' => 'apprenant@example.test'], ['REMOTE_ADDR' => '10.3.0.1'])->assertStatus(429);
        $unknown = $this->postJson('/api/v1/password/forgot', ['email' => 'autre@example.test'], ['REMOTE_ADDR' => '10.3.0.1'])->assertStatus(429);

        $this->assertSame($known->json('code'), $unknown->json('code'));
        Queue::assertPushed(SendPasswordLink::class, 10);
    }

    public function test_la_tache_en_file_est_chiffree_sans_adresse_lisible(): void
    {
        config(['queue.default' => 'database']);

        $this->postJson('/api/v1/password/forgot', ['email' => 'secret-adresse@example.test'])->assertStatus(202);

        $payload = (string) DB::table('jobs')->value('payload');
        $this->assertNotSame('', $payload);
        $this->assertStringNotContainsString('secret-adresse', $payload);
        $this->assertStringNotContainsString(LinkRequest::Forgot->value, $payload);
    }

    public function test_aucun_journal_pour_la_demande_elle_meme(): void
    {
        $this->accounts();
        Queue::fake();
        $before = DB::table('audit_logs')->count();

        $this->postJson('/api/v1/password/forgot', ['email' => 'apprenant@example.test'])->assertStatus(202);
        $this->postJson('/api/v1/password/forgot', ['email' => Str::random(8).'@example.test'], ['REMOTE_ADDR' => '10.4.0.2'])->assertStatus(202);

        $this->assertSame($before, DB::table('audit_logs')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', AuditAction::PasswordResetLinkSent->value)->count());
    }
}
