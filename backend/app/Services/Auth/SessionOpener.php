<?php

namespace App\Services\Auth;

use App\Enums\AuditAction;
use App\Http\Middleware\EnsureSessionIsValid;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ouverture d'une session (AUTH-01), commune à la connexion et à l'activation (T-05) :
 * régénération de l'identifiant, heure de connexion (limite de 12 h), date de dernière
 * connexion, journal. Ne ferme jamais les autres sessions (compte entreprise partagé).
 *
 * Les contrôles d'état et de rôle sont faits par l'appelant avant l'ouverture.
 */
class SessionOpener
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function open(Request $request, User $user): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(EnsureSessionIsValid::LOGGED_IN_AT, now()->getTimestamp());

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->audit->record(AuditAction::LoginSucceeded, $user, actor: $user);
    }
}
