<?php

namespace Tests;

use App\Http\Middleware\EnsureSessionIsValid;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
