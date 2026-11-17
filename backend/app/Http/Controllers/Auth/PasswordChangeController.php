<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditAction;
use App\Enums\PasswordTokenType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\AuditLogger;
use App\Services\Auth\SessionRevoker;
use App\Services\Passwords\PasswordTokenService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Changement de son mot de passe depuis « Mon profil » (AUTH-03), pour les 5 rôles.
 *
 * Exige le mot de passe actuel (RG-AUTH-11) ; ferme les AUTRES sessions du compte et garde la
 * session courante (RG-AUTH-12), sauf pour un compte entreprise dont les autres recruteurs
 * restent connectés (décision du 17/11/2026) ; annule les liens de réinitialisation en attente
 * (décision E du 17/11/2026).
 */
class PasswordChangeController extends Controller
{
    public function __construct(
        private readonly PasswordTokenService $tokens,
        private readonly SessionRevoker $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', new StrongPassword],
        ], [
            'current_password.required' => 'Saisissez votre mot de passe actuel.',
            'current_password.*' => 'Mot de passe actuel incorrect.',
            'password.required' => 'Saisissez un nouveau mot de passe.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $currentSessionId = $request->session()->getId();

        // Toutes les vérifications portent sur la ligne relue SOUS VERROU, jamais sur l'objet chargé
        // au début de la requête : une réinitialisation terminée entre-temps ne peut pas être
        // écrasée avec l'ancien mot de passe (revue Codex T-05, constat 1).
        $locked = DB::transaction(function () use ($user, $validated, $currentSessionId): User {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->isDeactivated()) {
                throw new AuthenticationException;
            }

            if (! Hash::check($validated['current_password'], $locked->password)) {
                throw ValidationException::withMessages(['current_password' => 'Mot de passe actuel incorrect.']);
            }

            if (Hash::check($validated['password'], $locked->password)) {
                throw ValidationException::withMessages(['password' => PasswordResetController::SAME_PASSWORD]);
            }

            // Hachage en vigueur avant le changement : sert à reconnaître les sessions encore valables
            // (compte entreprise). Gardé en mémoire uniquement, jamais journalisé.
            $previousHash = (string) $locked->password;

            User::withoutAccountAudit(function () use ($locked, $validated): void {
                $locked->forceFill(['password' => $validated['password']])->save();
            });
            $this->tokens->revokeAll($locked, PasswordTokenType::Reset);
            $closed = $this->sessions->afterPasswordChange($locked, $currentSessionId, $previousHash);
            $this->audit->record(AuditAction::PasswordChanged, $locked, new: [
                'note' => $closed ? 'Autres sessions fermées' : 'Autres sessions conservées (compte entreprise)',
            ], actor: $locked);

            return $locked;
        });

        // L'utilisateur du garde reçoit les valeurs enregistrées : AuthenticateSession écrit ainsi
        // l'empreinte du NOUVEAU mot de passe dans la session courante.
        $user->setRawAttributes($locked->getAttributes(), true);

        // Nouvel identifiant pour la session courante ; ses données (heure de connexion, donc la
        // limite de 12 h) sont conservées.
        $request->session()->migrate(true);

        return response()->noContent();
    }
}
