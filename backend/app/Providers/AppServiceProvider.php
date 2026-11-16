<?php

namespace App\Providers;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            $targetKey = self::loginTarget($request).'|'.$ip;
            $ipKey = 'login:ip:'.$ip;

            // Chaque limite transmet sa propre clé : le blocage est journalisé une fois par limite
            // réellement dépassée (compte + IP, ou IP seule), quel que soit l'identifiant essayé.
            return [
                Limit::perMinute(5)->by($targetKey)
                    ->response(fn (Request $r, array $headers) => self::tooManyAttempts($r, $headers, $targetKey, 'compte')),
                Limit::perMinute(100)->by($ipKey)
                    ->response(fn (Request $r, array $headers) => self::tooManyAttempts($r, $headers, $ipKey, 'adresse_ip')),
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
     * Journalise un blocage de connexion (TRA-01, décision du 16/11/2026), une seule fois par
     * blocage de la limite dépassée : les tentatives suivantes pendant le délai, même sur
     * d'autres identifiants, ne créent pas de nouvelle ligne (pas de remplissage du journal).
     */
    private static function recordThrottled(Request $request, int $seconds, string $limitKey, string $limitType): void
    {
        if (! Cache::add('audit:login-throttled:'.sha1($limitKey), true, $seconds)) {
            return;
        }

        $raw = $request->input('identifier');
        $identifier = is_string($raw) ? trim($raw) : '';

        app(AuditLogger::class)->record(
            AuditAction::LoginThrottled,
            $identifier !== '' ? User::findForLogin($identifier) : null,
            new: ['limite' => $limitType, 'identifiant' => mb_substr($identifier, 0, 255), 'delai_secondes' => $seconds],
        );
    }

    /**
     * @param  array<string, string|int>  $headers
     */
    private static function tooManyAttempts(Request $request, array $headers, string $limitKey, string $limitType): JsonResponse
    {
        $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));

        self::recordThrottled($request, $seconds, $limitKey, $limitType);

        return response()->json([
            'message' => "Trop de tentatives de connexion. Réessayez dans {$seconds} secondes.",
            'code' => 'too_many_attempts',
            'retry_after' => $seconds,
        ], 429, $headers);
    }
}
