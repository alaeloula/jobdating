<?php

namespace App\Enums;

/**
 * Catégories du journal d'audit, utilisées comme filtres (maquette admin-13).
 * « Connexions » est ajoutée (décision du 16/11/2026 : connexions journalisées).
 */
enum AuditCategory: string
{
    case Connexions = 'connexions';
    case Comptes = 'comptes';
    case Passages = 'passages';
    case Cv = 'cv';
    case Cnie = 'cnie';
    case ImportsExports = 'imports_exports';
    case Referentiels = 'referentiels';

    public function label(): string
    {
        return match ($this) {
            self::Connexions => 'Connexions',
            self::Comptes => 'Comptes et mots de passe',
            self::Passages => 'Passages et évaluations',
            self::Cv => 'Consultations de CV',
            self::Cnie => 'Consultations de CNIE',
            self::ImportsExports => 'Imports et exports',
            self::Referentiels => 'Référentiels',
        };
    }
}
