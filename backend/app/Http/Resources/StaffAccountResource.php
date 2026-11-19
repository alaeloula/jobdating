<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Compte de l'équipe dans l'écran « Comptes » (ADM-01). Jamais de mot de passe ni de jeton.
 *
 * @mixin User
 */
class StaffAccountResource extends JsonResource
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
            'full_name' => trim($user->first_name.' '.$user->last_name),
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $role?->value,
            'role_label' => $role?->label(),
            'status' => self::status($user),
            'last_login_at' => $user->last_login_at !== null ? Carbon::parse($user->last_login_at)->toIso8601String() : null,
            'is_self' => $request->user()?->getAuthIdentifier() === $user->id,
        ];
    }

    /** « supprime », « desactive », « invitation » (pas encore activé) ou « actif ». */
    public static function status(User $user): string
    {
        return match (true) {
            $user->trashed() => 'supprime',
            $user->isDeactivated() => 'desactive',
            ! $user->isActivated() => 'invitation',
            default => 'actif',
        };
    }
}
