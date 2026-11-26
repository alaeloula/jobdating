<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Nom simplifié d'une entreprise (décision du 25/11, T-64) : translittération ASCII, minuscules, puis
 * SEULS les lettres et chiffres sont conservés (« Société Générale », « SOCIETE-GENERALE » →
 * `societegenerale`). Distinct du nom d'utilisateur.
 *
 * La clé enregistrée (`companies.name_key`) est l'empreinte SHA-256 du nom simplifié COMPLET, jamais
 * une troncature (contre-revue Codex du plan T-64, constat 1).
 */
final class CompanyNameKey
{
    public static function normalize(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($value)));
    }

    /** Null pour un nom sans lettre ni chiffre : il est refusé AVANT tout calcul d'empreinte. */
    public static function of(string $name): ?string
    {
        $normalized = self::normalize($name);

        return $normalized === '' ? null : hash('sha256', $normalized);
    }
}
