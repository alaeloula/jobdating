<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PC-05 : plusieurs recruteurs partagent les identifiants du compte Entreprise,
 * avec plusieurs sessions actives simultanément.
 */
class CompanyConcurrentSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_deux_recruteurs_restent_connectes_au_meme_compte_et_se_deconnectent_independamment(): void
    {
        $company = User::factory()->withRole(Role::Entreprise)->create(['username' => 'entreprise-partagee']);
        $credentials = ['identifier' => 'entreprise-partagee', 'password' => 'password'];
        $firstBrowser = [];
        $secondBrowser = [];

        $this->browserRequest($firstBrowser, 'POST', '/api/v1/login', $credentials)
            ->assertOk()
            ->assertJsonPath('data.id', $company->id)
            ->assertJsonPath('data.role', 'entreprise');

        $firstSessionIds = DB::table('sessions')->where('user_id', $company->id)->pluck('id')->all();
        $this->assertCount(1, $firstSessionIds);

        $this->browserRequest($secondBrowser, 'POST', '/api/v1/login', $credentials)
            ->assertOk()
            ->assertJsonPath('data.id', $company->id)
            ->assertJsonPath('data.role', 'entreprise');

        // Deux sessions persistées, et la seconde connexion n'a pas remplacé la première.
        $sessionIds = DB::table('sessions')->where('user_id', $company->id)->pluck('id')->all();
        $this->assertCount(2, $sessionIds);
        $this->assertContains($firstSessionIds[0], $sessionIds);

        $this->browserRequest($firstBrowser, 'GET', '/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $company->id);
        $this->browserRequest($secondBrowser, 'GET', '/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $company->id);

        $this->browserRequest($firstBrowser, 'POST', '/api/v1/logout')->assertNoContent();

        $this->browserRequest($firstBrowser, 'GET', '/api/v1/me')->assertUnauthorized();
        $this->browserRequest($secondBrowser, 'GET', '/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $company->id);

        $this->assertSame(1, DB::table('sessions')->where('user_id', $company->id)->count());
        $this->assertDatabaseMissing('sessions', ['id' => $firstSessionIds[0]]);

        $this->browserRequest($secondBrowser, 'POST', '/api/v1/logout')->assertNoContent();
        $this->browserRequest($secondBrowser, 'GET', '/api/v1/me')->assertUnauthorized();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $company->id)->count());
    }

    public function test_la_desactivation_du_compte_entreprise_invalide_les_deux_sessions_a_leur_requete_suivante(): void
    {
        $company = User::factory()->withRole(Role::Entreprise)->create(['username' => 'entreprise-partagee']);
        $credentials = ['identifier' => 'entreprise-partagee', 'password' => 'password'];
        $firstBrowser = [];
        $secondBrowser = [];

        $this->browserRequest($firstBrowser, 'POST', '/api/v1/login', $credentials)->assertOk();
        $this->browserRequest($secondBrowser, 'POST', '/api/v1/login', $credentials)->assertOk();
        $this->browserRequest($firstBrowser, 'GET', '/api/v1/me')->assertOk();
        $this->browserRequest($secondBrowser, 'GET', '/api/v1/me')->assertOk();

        $sessionIds = DB::table('sessions')->where('user_id', $company->id)->pluck('id')->all();
        $this->assertCount(2, $sessionIds);

        $company->forceFill(['deactivated_at' => now()])->save();

        $this->browserRequest($firstBrowser, 'GET', '/api/v1/me')->assertUnauthorized();
        $this->browserRequest($secondBrowser, 'GET', '/api/v1/me')->assertUnauthorized();

        foreach ($sessionIds as $sessionId) {
            $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        }

        // Une réponse 401 pendant la désactivation ne suffirait pas à prouver
        // l'invalidation : les anciens cookies restent refusés après réactivation.
        $company->forceFill(['deactivated_at' => null])->save();

        $this->browserRequest($firstBrowser, 'GET', '/api/v1/me')->assertUnauthorized();
        $this->browserRequest($secondBrowser, 'GET', '/api/v1/me')->assertUnauthorized();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $company->id)->count());
    }

    /**
     * Chaque navigateur conserve ses cookies chiffrés reçus du serveur. Les gardes
     * et le stockage de session en mémoire sont recréés entre les requêtes : seul
     * le cookie du navigateur et la session MySQL peuvent l'authentifier.
     *
     * @param  array<string, string>  $cookies
     * @param  array<string, string>  $body
     * @return TestResponse<Response>
     */
    private function browserRequest(array &$cookies, string $method, string $path, array $body = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        $request = Request::create('http://localhost'.$path, $method, [], $cookies, [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            // Deux recruteurs peuvent aussi partager le Wi-Fi de l'entreprise.
            'REMOTE_ADDR' => '10.0.0.20',
        ], json_encode($body, JSON_THROW_ON_ERROR));

        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);

        foreach ($response->headers->getCookies() as $cookie) {
            $value = $cookie->getValue();

            if ($value === null) {
                unset($cookies[$cookie->getName()]);
            } else {
                $cookies[$cookie->getName()] = $value;
            }
        }

        $kernel->terminate($request, $response);

        return TestResponse::fromBaseResponse($response);
    }
}
