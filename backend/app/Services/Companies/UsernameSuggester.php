<?php

namespace App\Services\Companies;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Nom d'utilisateur d'une entreprise (RG-ENTR-04, plan T-11 v2, section 2).
 *
 * Nom simplifié (minuscules, sans accent, mots séparés par un tiret), chiffre ajouté en cas de
 * doublon, comptes supprimés compris. Toute proposition respecte le format et la longueur
 * (3 à 50 caractères). Aucune réservation : une collision au dernier moment est gérée par la
 * contrainte d'unicité SQL au moment de l'enregistrement.
 */
final class UsernameSuggester
{
    public const MIN = 3;

    public const MAX = 50;

    public const PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    private const FALLBACK = 'entreprise';

    /**
     * @param  list<string>  $reserved  noms déjà proposés dans le même lot (import T-64), évités comme
     *                                  des comptes existants, après troncature
     */
    public static function suggest(string $companyName, array $reserved = []): string
    {
        $base = self::base($companyName);

        for ($n = 1; $n < 10000; $n++) {
            $suffix = $n === 1 ? '' : '-'.$n;
            $candidate = self::fit($base, strlen($suffix)).$suffix;

            if (! in_array($candidate, $reserved, true) && ! self::taken($candidate)) {
                return $candidate;
            }
        }

        return self::fit($base, 9).'-'.Str::lower(Str::random(8));
    }

    /** Base simplifiée, d'au moins 3 caractères. */
    public static function base(string $companyName): string
    {
        $slug = Str::slug(Str::ascii($companyName), '-');

        if ($slug === '') {
            return self::FALLBACK;
        }

        // « HP » → « hp-entreprise » : jamais une proposition trop courte.
        return strlen($slug) < self::MIN ? $slug.'-'.self::FALLBACK : $slug;
    }

    public static function valid(string $username): bool
    {
        return strlen($username) >= self::MIN && strlen($username) <= self::MAX
            && preg_match(self::PATTERN, $username) === 1;
    }

    public static function taken(string $username): bool
    {
        return User::withTrashed()->where('username', $username)->exists();
    }

    /** Coupe la base pour laisser la place au suffixe, sans tiret final. */
    private static function fit(string $base, int $suffixLength): string
    {
        return rtrim(substr($base, 0, self::MAX - $suffixLength), '-');
    }
}
