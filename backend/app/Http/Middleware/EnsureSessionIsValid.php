<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vérifie à chaque requête que la session reste valable.
 *
 * - Compte désactivé : la session est fermée immédiatement (RG-ACC-05). Un compte
 *   supprimé (suppression logique) n'est déjà plus retrouvé par le garde.
 * - Durée maximale de 12 heures depuis la connexion (RG-AUTH-02), même si
 *   l'utilisateur reste actif : la durée de vie Laravel seule est une durée d'inactivité.
 */
class EnsureSessionIsValid
{
    public const LOGGED_IN_AT = 'auth.logged_in_at';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ($user->isDeactivated() || $this->isExpired($request))) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new AuthenticationException;
        }

        return $next($request);
    }

    private function isExpired(Request $request): bool
    {
        $loggedInAt = $request->session()->get(self::LOGGED_IN_AT);

        // Session sans heure de connexion (non ouverte par POST /login) : refusée.
        // Dans les tests, TestCase::actingAs() renseigne cette heure.
        if (! is_int($loggedInAt)) {
            return true;
        }

        $maxSeconds = (int) config('session.lifetime') * 60;

        return now()->getTimestamp() - $loggedInAt >= $maxSeconds;
    }
}
