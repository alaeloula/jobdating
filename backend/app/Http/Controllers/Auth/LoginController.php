<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureSessionIsValid;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\CurrentUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Connexion (AUTH-01) : session par cookie Sanctum, sans jeton (CDC 9.5).
 *
 * Les tentatives sont limitées par le limiteur « login » (RG-AUTH-01).
 */
class LoginController extends Controller
{
    public const INVALID_CREDENTIALS = 'Identifiants incorrects.';

    public const DEACTIVATED = 'Votre compte est désactivé. Contactez l\'administration.';

    public const NOT_ACTIVATED = 'Votre compte n\'est pas encore activé. Utilisez le lien d\'activation reçu par e-mail.';

    public const NO_ROLE = 'Votre compte n\'a pas de rôle. Contactez l\'administration.';

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = User::findForLogin($request->identifier());

        // Le mot de passe est toujours vérifié, y compris pour un identifiant inconnu (hash factice) :
        // la durée de réponse ne révèle pas l'existence du compte.
        $passwordMatches = Hash::check(
            $request->string('password')->toString(),
            $user !== null ? $user->password : self::dummyHash(),
        );

        // Message générique : il ne dit pas si l'identifiant ou le mot de passe est faux.
        if ($user === null || ! $passwordMatches) {
            return $this->refuse(422, 'invalid_credentials', self::INVALID_CREDENTIALS);
        }

        // Le motif précis n'est donné qu'après un mot de passe correct.
        if ($user->isDeactivated()) {
            return $this->refuse(403, 'account_deactivated', self::DEACTIVATED);
        }

        if (! $user->isActivated()) {
            return $this->refuse(403, 'account_not_activated', self::NOT_ACTIVATED);
        }

        if ($user->currentRole() === null) {
            return $this->refuse(403, 'account_without_role', self::NO_ROLE);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(EnsureSessionIsValid::LOGGED_IN_AT, now()->getTimestamp());

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return CurrentUserResource::make($user)->response();
    }

    /**
     * Hash d'un secret aléatoire, au même coût que les vrais mots de passe.
     * Calculé une fois puis gardé en cache (la clé suit le coût configuré).
     */
    private static function dummyHash(): string
    {
        $rounds = (int) config('hashing.bcrypt.rounds', 12);

        return Cache::rememberForever(
            "auth:login:dummy-hash:{$rounds}",
            fn (): string => Hash::make(Str::random(40)),
        );
    }

    private function refuse(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
