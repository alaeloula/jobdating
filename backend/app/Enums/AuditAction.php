<?php

namespace App\Enums;

/**
 * Actions journalisées (TRA-01). Les actions des tâches suivantes (passages, évaluations,
 * CV, imports, exports) s'ajouteront ici, avec leur catégorie et leur libellé.
 */
enum AuditAction: string
{
    case LoginSucceeded = 'auth.login_succeeded';
    case LoginFailed = 'auth.login_failed';
    case LoginThrottled = 'auth.login_throttled';
    case Logout = 'auth.logout';

    case AccountCreated = 'account.created';
    case AccountUpdated = 'account.updated';
    case AccountDeactivated = 'account.deactivated';
    case AccountReactivated = 'account.reactivated';
    case AccountDeleted = 'account.deleted';
    case AccountRestored = 'account.restored';
    case RoleChanged = 'account.role_changed';

    public function category(): AuditCategory
    {
        return match ($this) {
            self::LoginSucceeded, self::LoginFailed, self::LoginThrottled, self::Logout => AuditCategory::Connexions,
            default => AuditCategory::Comptes,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::LoginSucceeded => 'Connexion réussie',
            self::LoginFailed => 'Connexion refusée',
            self::LoginThrottled => 'Connexion bloquée (trop de tentatives)',
            self::Logout => 'Déconnexion',
            self::AccountCreated => 'Compte créé',
            self::AccountUpdated => 'Compte modifié',
            self::AccountDeactivated => 'Compte désactivé',
            self::AccountReactivated => 'Compte réactivé',
            self::AccountDeleted => 'Compte supprimé',
            self::AccountRestored => 'Compte restauré',
            self::RoleChanged => 'Rôle modifié',
        };
    }
}
