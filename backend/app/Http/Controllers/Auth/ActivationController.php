<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditAction;
use App\Enums\PasswordTokenType;
use App\Http\Controllers\Auth\Concerns\HandlesPasswordLinks;
use App\Http\Controllers\Controller;
use App\Http\Resources\CurrentUserResource;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\SessionOpener;
use App\Services\Passwords\PasswordTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Activation d'un compte CME, Direction ou administrateur (AUTH-02) : le lien (48 h, usage
 * unique) permet de choisir son mot de passe, puis ouvre la session.
 *
 * Le jeton arrive toujours dans le corps d'un POST, jamais dans une adresse.
 */
class ActivationController extends Controller
{
    use HandlesPasswordLinks;

    public function __construct(
        private readonly PasswordTokenService $tokens,
        private readonly SessionOpener $sessions,
        private readonly AuditLogger $audit,
    ) {}

    /** Le lien est-il valable ? Prénom seulement (pas d'autre donnée avant l'activation). */
    public function check(Request $request): JsonResponse
    {
        $user = $this->tokens->check($request->input('token'), PasswordTokenType::Activation);

        return response()->json(['data' => ['first_name' => $user->first_name]]);
    }

    public function activate(Request $request): JsonResponse
    {
        $validated = $request->validate($this->newPasswordRules(), $this->newPasswordMessages());

        $this->closeForeignSession($request, $this->tokens->check($validated['token'], PasswordTokenType::Activation));

        // Activation ET ouverture de session dans la même transaction, en tout ou rien : si le
        // journal de connexion échoue, rien n'est enregistré, la session commencée est refermée et
        // le lien reste utilisable (revue Codex T-05, constat 3).
        try {
            $user = $this->tokens->consume($validated['token'], PasswordTokenType::Activation, function (User $user) use ($request, $validated): User {
                User::withoutAccountAudit(function () use ($user, $validated): void {
                    $user->forceFill(['password' => $validated['password'], 'activated_at' => now()])->save();
                });
                $this->audit->record(AuditAction::AccountActivated, $user, actor: $user);

                // AUTH-02 : « choisit son mot de passe et accède à son espace ».
                $this->sessions->open($request, $user);

                return $user;
            });
        } catch (Throwable $e) {
            if (Auth::guard('web')->check()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            throw $e;
        }

        return CurrentUserResource::make($user)->response();
    }
}
