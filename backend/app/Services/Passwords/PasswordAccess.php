<?php

namespace App\Services\Passwords;

use App\Enums\PasswordTokenType;
use App\Enums\Role;
use App\Models\User;

/**
 * Qui peut recevoir quel lien (T-05, décisions du 14/11 et du 17/11/2026).
 *
 * - Activation (AUTH-02) : comptes CME, Direction et administrateur seulement, non activés,
 *   non désactivés, avec un e-mail. Entreprises et apprenants n'ont pas d'activation par lien.
 * - Réinitialisation (AUTH-03) : apprenant, CME, Direction et administrateur, activés, non
 *   désactivés, avec un e-mail. L'entreprise s'adresse à l'administrateur (RG-ENTR-07).
 *
 * Un compte supprimé (suppression logique) n'est jamais retrouvé par les requêtes : inéligible.
 */
final class PasswordAccess
{
    private const ACTIVATION_ROLES = [Role::Administrateur, Role::Cme, Role::Direction];

    private const RESET_ROLES = [Role::Administrateur, Role::Cme, Role::Direction, Role::Apprenant];

    public static function canActivate(User $user): bool
    {
        return ! $user->trashed()
            && ! $user->isActivated()
            && ! $user->isDeactivated()
            && self::hasEmail($user)
            && in_array($user->currentRole(), self::ACTIVATION_ROLES, true);
    }

    public static function canReset(User $user): bool
    {
        return ! $user->trashed()
            && $user->isActivated()
            && ! $user->isDeactivated()
            && self::hasEmail($user)
            && in_array($user->currentRole(), self::RESET_ROLES, true);
    }

    public static function eligible(User $user, PasswordTokenType $type): bool
    {
        return match ($type) {
            PasswordTokenType::Activation => self::canActivate($user),
            PasswordTokenType::Reset => self::canReset($user),
        };
    }

    private static function hasEmail(User $user): bool
    {
        return is_string($user->email) && $user->email !== '';
    }
}
