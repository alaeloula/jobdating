<?php

namespace App\Support;

/**
 * Règle unique de la CNIE (ou passeport, carte de séjour) : saisie, recherche et import (T-13).
 * Décision du 19/11/2026 : lettres et chiffres, 5 à 30 caractères, majuscules. Seuls les ESPACES
 * sont retirés ; tout autre caractère est refusé, jamais supprimé en silence. L'identifiant reste
 * une chaîne (zéros initiaux conservés).
 */
final class Cnie
{
    /** Ancrage strict (\A … \z) : une valeur suivie d'un retour à la ligne est refusée (contre-revue T-12). */
    public const PATTERN = '/\A[A-Z0-9]{5,30}\z/';

    public const MESSAGE = 'La CNIE doit contenir de 5 à 30 lettres ou chiffres, sans autre caractère.';

    public static function normalize(string $value): string
    {
        return mb_strtoupper(str_replace(' ', '', $value));
    }

    public static function isValid(string $normalized): bool
    {
        return preg_match(self::PATTERN, $normalized) === 1;
    }
}
