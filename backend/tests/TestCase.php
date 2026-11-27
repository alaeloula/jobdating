<?php

namespace Tests;

use App\Http\Middleware\EnsureSessionIsValid;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /**
     * Le disque `local` (CV chiffrés, T-20) est TOUJOURS isolé dans les tests : dans Docker, le dossier
     * `storage/` est celui du poste de développement, alors que la base est `jobdating_test`. Sans cette
     * isolation, le nettoyage des fichiers de CV non référencés (`imports:cleanup`) supprimait les vrais
     * fichiers locaux (incident du 27/11, T-21).
     */
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * Ouvre une session comme POST /login : avec l'heure de connexion, sans laquelle
     * le middleware session.valid refuse la session (RG-AUTH-02).
     *
     * Pour simuler une session sans heure de connexion, utiliser be().
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);

        return $this->withSession([EnsureSessionIsValid::LOGGED_IN_AT => now()->getTimestamp()]);
    }
}
