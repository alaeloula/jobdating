<?php

namespace App\Providers;

use App\Contracts\ClassUsage;
use App\Contracts\TagUsage;
use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Learners\LearnerClassUsage;
use App\Services\Referentials\NoTagUsage;
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
        // Points d'extension des référentiels (T-02) : apprenants d'une classe (branché par T-12) ;
        // étiquettes utilisées dans des évaluations (à remplacer par T-31).
        $this->app->bind(ClassUsage::class, LearnerClassUsage::class);
        $this->app->bind(TagUsage::class, NoTagUsage::class);
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
        // T-05 : vérification et utilisation des liens, 10 / min par IP (contre l'abus ; un jeton
        // de 64 caractères ne se devine pas).
        RateLimiter::for('password-token', fn (Request $request) => Limit::perMinute(10)
            ->by('password-token:'.$request->ip())
            ->response(fn (Request $r, array $headers) => self::tooManyRequests($headers)));

        // T-05 : changement depuis le profil, 5 tentatives / min par compte (RG-AUTH-11).
        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)
            ->by('password-change:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response(fn (Request $r, array $headers) => self::tooManyRequests($headers)));

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
    private static function tooManyRequests(array $headers): JsonResponse
    {
        $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));

        return response()->json([
            'message' => "Trop de demandes. Réessayez dans {$seconds} secondes.",
            'code' => 'too_many_requests',
            'retry_after' => $seconds,
        ], 429, $headers);
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
