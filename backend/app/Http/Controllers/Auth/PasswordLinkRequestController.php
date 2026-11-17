<?php

namespace App\Http\Controllers\Auth;

use App\Enums\LinkRequest;
use App\Http\Controllers\Controller;
use App\Jobs\SendPasswordLink;
use App\Services\Passwords\PasswordEmailThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Demandes publiques de lien (T-05) : « Mot de passe oublié » (AUTH-03) et « Recevoir un
 * nouveau lien d'activation » (AUTH-02).
 *
 * Même travail et même réponse pour toute adresse valide, que le compte existe ou non
 * (RG-AUTH-09) : validation, limites, une tâche mise en file, 202. Aucune lecture de la table
 * `users` ici : la tâche décide en arrière-plan.
 */
class PasswordLinkRequestController extends Controller
{
    public const MESSAGE = 'Si un compte correspond à cette adresse, un e-mail vient d’être envoyé.';

    public function __construct(private readonly PasswordEmailThrottle $throttle) {}

    public function forgot(Request $request): JsonResponse
    {
        return $this->handle($request, LinkRequest::Forgot);
    }

    public function resendActivation(Request $request): JsonResponse
    {
        return $this->handle($request, LinkRequest::ActivationResend);
    }

    private function handle(Request $request, LinkRequest $kind): JsonResponse
    {
        // Espaces de début et de fin déjà retirés (TrimStrings) ; une adresse avec des espaces
        // internes est refusée ici.
        $email = $request->validate(
            ['email' => ['required', 'string', 'max:255', 'email:rfc']],
            ['email.required' => 'Saisissez votre adresse e-mail.', 'email.*' => 'Adresse e-mail invalide.'],
        )['email'];

        if ($blocked = $this->throttle->attempt($email, (string) $request->ip())) {
            return $blocked;
        }

        dispatch(SendPasswordLink::forPublicRequest($kind, $email));

        return response()->json(['message' => self::MESSAGE], 202);
    }
}
