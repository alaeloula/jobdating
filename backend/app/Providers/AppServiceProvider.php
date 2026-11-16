<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * RG-AUTH-01 : 5 tentatives par minute pour un même identifiant depuis une même
     * adresse IP, et 100 par minute par adresse IP seule (Wi-Fi partagé de l'événement).
     * Au-delà : HTTP 429 avec le délai d'attente.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by(self::loginTarget($request).'|'.$ip)->response(self::tooManyAttempts(...)),
                Limit::perMinute(100)->by('login:ip:'.$ip)->response(self::tooManyAttempts(...)),
            ];
        });
    }

    /**
     * Cible du compteur par identifiant.
     *
     * - Compte existant : son id. Toutes les écritures qui désignent ce compte pour MySQL
     *   (casse, accents, espaces) partagent ainsi le même compteur.
     * - Identifiant inconnu : sa forme normalisée (minuscules, sans accents).
     * - Valeur non textuelle (tableau, objet…) : compteur commun ; la validation la rejette ensuite (422).
     */
    private static function loginTarget(Request $request): string
    {
        $raw = $request->input('identifier');

        if (! is_string($raw)) {
            return 'login:invalid';
        }

        $identifier = trim($raw);
        $user = User::findForLogin($identifier);

        return $user !== null
            ? 'login:user:'.$user->getKey()
            : 'login:identifier:'.Str::lower(Str::ascii($identifier));
    }

    /**
     * @param  array<string, string|int>  $headers
     */
    private static function tooManyAttempts(Request $request, array $headers): JsonResponse
    {
        $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));

        return response()->json([
            'message' => "Trop de tentatives de connexion. Réessayez dans {$seconds} secondes.",
            'code' => 'too_many_attempts',
            'retry_after' => $seconds,
        ], 429, $headers);
    }
}
