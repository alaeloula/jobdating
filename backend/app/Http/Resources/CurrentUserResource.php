<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Utilisateur connecté : identité, rôle, permissions et page d'accueil du rôle.
 *
 * Les permissions servent seulement à adapter l'interface : le serveur vérifie
 * chaque droit à chaque requête (RG-ACC-01).
 *
 * @mixin User
 */
class CurrentUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $role = $user->currentRole();

        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'username' => $user->username,
            'role' => $role?->value,
            'role_label' => $role?->label(),
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
            'home' => $role?->homePath(),
        ];
    }
}
