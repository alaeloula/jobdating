<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditAction;
use App\Enums\PasswordTokenType;
use App\Http\Controllers\Auth\Concerns\HandlesPasswordLinks;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\SessionRevoker;
use App\Services\Passwords\PasswordTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Réinitialisation du mot de passe par lien (AUTH-03, 60 min, usage unique).
 *
 * Toutes les sessions du compte sont fermées (RG-AUTH-12) ; pas de connexion automatique :
 * retour à l'écran de connexion (décision du 17/11/2026).
 */
class PasswordResetController extends Controller
{
    use HandlesPasswordLinks;

    public const SAME_PASSWORD = 'Choisissez un mot de passe différent de l’actuel.';

    public function __construct(
        private readonly PasswordTokenService $tokens,
        private readonly SessionRevoker $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function check(Request $request): JsonResponse
    {
        $this->tokens->check($request->input('token'), PasswordTokenType::Reset);

        return response()->json(['data' => ['valid' => true]]);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate($this->newPasswordRules(), $this->newPasswordMessages());

        $target = $this->tokens->check($validated['token'], PasswordTokenType::Reset);
        $this->closeForeignSession($request, $target);

        $this->tokens->consume($validated['token'], PasswordTokenType::Reset, function (User $user) use ($validated): void {
            // Décision D : le nouveau mot de passe diffère de l'actuel. L'exception annule tout :
            // le lien reste utilisable.
            if (Hash::check($validated['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => self::SAME_PASSWORD]);
            }

            User::withoutAccountAudit(function () use ($user, $validated): void {
                $user->forceFill(['password' => $validated['password']])->save();
            });
            $this->sessions->closeAll($user);
            $this->audit->record(AuditAction::PasswordReset, $user, new: ['note' => 'Sessions fermées'], actor: $user);
        });

        // Le titulaire qui était connecté ici perd aussi cette session.
        if (Auth::guard('web')->id() === $target->getKey()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Mot de passe modifié. Vous pouvez vous connecter.']);
    }
}
