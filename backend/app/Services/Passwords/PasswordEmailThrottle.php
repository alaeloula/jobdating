<?php

namespace App\Services\Passwords;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Limites des demandes publiques de lien (RG-AUTH-10, décision du 17/11/2026), budget COMMUN à
 * « mot de passe oublié » et « nouveau lien d'activation » :
 * - 1 par minute par adresse ;
 * - 10 par minute par adresse IP.
 *
 * La clé d'adresse est calculée par MySQL avec la collation de la colonne `email`, sans lire la
 * table `users` : deux adresses partagent le budget si et seulement si MySQL les juge identiques
 * (« jose » et « josé » oui ; « a » latin et « а » cyrillique non). Elle ne sert jamais d'adresse.
 * Les deux budgets sont consommés quelle que soit l'existence du compte.
 */
class PasswordEmailThrottle
{
    public const PER_EMAIL = 1;

    public const PER_IP = 10;

    private const DECAY_SECONDS = 60;

    /**
     * Réponse 429 si une limite est atteinte (identique pour toutes les adresses), sinon null
     * après avoir consommé les deux budgets.
     */
    public function attempt(string $email, string $ip): ?JsonResponse
    {
        $emailKey = 'password-email:'.$this->emailKey($email);
        $ipKey = 'password-email-ip:'.$ip;

        $blocked = max(
            RateLimiter::tooManyAttempts($emailKey, self::PER_EMAIL) ? RateLimiter::availableIn($emailKey) : 0,
            RateLimiter::tooManyAttempts($ipKey, self::PER_IP) ? RateLimiter::availableIn($ipKey) : 0,
        );

        if ($blocked > 0) {
            return response()->json([
                'message' => "Trop de demandes. Réessayez dans {$blocked} secondes.",
                'code' => 'too_many_requests',
                'retry_after' => $blocked,
            ], 429, ['Retry-After' => (string) $blocked]);
        }

        RateLimiter::hit($emailKey, self::DECAY_SECONDS);
        RateLimiter::hit($ipKey, self::DECAY_SECONDS);

        return null;
    }

    public function emailKey(string $email): string
    {
        $row = DB::selectOne(
            'SELECT SHA2(WEIGHT_STRING(CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci), 256) AS k',
            [$email],
        );

        return (string) $row->k;
    }
}
