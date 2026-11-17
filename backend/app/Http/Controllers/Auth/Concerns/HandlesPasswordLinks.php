<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Models\User;
use App\Rules\StrongPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Éléments communs aux pages d'activation et de réinitialisation (T-05).
 */
trait HandlesPasswordLinks
{
    /**
     * @return array<string, mixed>
     */
    private function newPasswordRules(): array
    {
        return [
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', new StrongPassword],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function newPasswordMessages(): array
    {
        return [
            'token.*' => 'Lien invalide.',
            'password.required' => 'Saisissez un mot de passe.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
        ];
    }

    /**
     * Décision B (17/11/2026) : un AUTRE compte connecté sur ce navigateur est déconnecté avant
     * que le lien soit utilisé, côté serveur aussi (indépendamment du frontend).
     */
    private function closeForeignSession(Request $request, User $target): void
    {
        $current = Auth::guard('web')->user();

        if ($current !== null && $current->getAuthIdentifier() !== $target->getKey()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
