<?php

namespace App\Enums;

/**
 * Liens à usage unique (T-05) : activation (AUTH-02, 48 h, RG-AUTH-06) et réinitialisation
 * du mot de passe (AUTH-03, 60 min).
 */
enum PasswordTokenType: string
{
    case Activation = 'activation';
    case Reset = 'reset';

    public function lifetimeMinutes(): int
    {
        return match ($this) {
            self::Activation => 48 * 60,
            self::Reset => 60,
        };
    }

    public function sentAction(): AuditAction
    {
        return match ($this) {
            self::Activation => AuditAction::ActivationLinkSent,
            self::Reset => AuditAction::PasswordResetLinkSent,
        };
    }

    /** Libellé pour le journal (jamais le jeton). */
    public function label(): string
    {
        return match ($this) {
            self::Activation => 'activation',
            self::Reset => 'réinitialisation',
        };
    }
}
