<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use RuntimeException;

/**
 * Politique de mot de passe (RG-AUTH-07, décision du 17/11/2026), appliquée partout où un
 * utilisateur choisit son mot de passe : activation, réinitialisation, changement.
 *
 * - 10 à 64 caractères (Unicode) et au plus 72 octets : bcrypt ignore ce qui dépasse 72 octets,
 *   on refuse donc plutôt que de tronquer en silence ;
 * - refus des 10 000 mots de passe les plus courants (liste locale, sans service externe).
 *
 * Le mot de passe est enregistré tel quel (espaces compris) : seule la comparaison avec la
 * liste se fait en minuscules et sans les espaces de début et de fin.
 */
class StrongPassword implements ValidationRule
{
    public const MIN_LENGTH = 10;

    public const MAX_LENGTH = 64;

    public const MAX_BYTES = 72;

    public const LIST_PATH = 'security/common-passwords.txt';

    /** @var array<string, true>|null Liste chargée une fois par processus. */
    private static ?array $common = null;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Le mot de passe doit être un texte.');

            return;
        }

        $length = mb_strlen($value);

        if ($length < self::MIN_LENGTH) {
            $fail('10 caractères minimum.');

            return;
        }

        if ($length > self::MAX_LENGTH) {
            $fail('Le mot de passe ne doit pas dépasser 64 caractères.');

            return;
        }

        if (strlen($value) > self::MAX_BYTES) {
            $fail('Ce mot de passe est trop long.');

            return;
        }

        if (isset(self::commonPasswords()[mb_strtolower(trim($value))])) {
            $fail('Ce mot de passe est trop courant. Choisissez-en un autre.');
        }
    }

    /**
     * @return array<string, true>
     */
    private static function commonPasswords(): array
    {
        if (self::$common !== null) {
            return self::$common;
        }

        // Emplacement modifiable par la configuration (tests du fichier absent).
        $path = (string) config('security.common_passwords', resource_path(self::LIST_PATH));
        $lines = is_readable($path) ? file($path, FILE_IGNORE_NEW_LINES) : false;

        $list = [];
        foreach ($lines === false ? [] : $lines as $line) {
            $word = trim($line);
            if ($word !== '' && ! str_starts_with($word, '#')) {
                $list[mb_strtolower($word)] = true;
            }
        }

        // Jamais de validation silencieuse sans la liste (décision du 17/11/2026).
        if ($list === []) {
            throw new RuntimeException('Liste des mots de passe courants absente ou vide : '.self::LIST_PATH);
        }

        return self::$common = $list;
    }

    /** Pour les tests : force un nouveau chargement de la liste. */
    public static function flushCache(): void
    {
        self::$common = null;
    }

    /** Pour les tests : liste chargée ou non. */
    public static function isLoaded(): bool
    {
        return self::$common !== null;
    }
}
