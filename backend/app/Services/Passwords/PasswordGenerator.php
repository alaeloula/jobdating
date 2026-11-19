<?php

namespace App\Services\Passwords;

use App\Rules\StrongPassword;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Mots de passe générés (RG-AUTH-08, décision du 14/11/2026) : réinitialisation par
 * l'administrateur (T-10), puis comptes entreprise et apprenant (T-11, T-12).
 *
 * 16 caractères tirés au hasard (générateur cryptographique) dans un alphabet sans caractères
 * ambigus (0/O, 1/l/I) : faciles à recopier depuis l'écran ou une fiche imprimée. Toujours
 * conformes à la règle commune (StrongPassword : longueur, 72 octets, liste des mots courants).
 */
final class PasswordGenerator
{
    public const LENGTH = 16;

    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function generate(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $password = '';
            $max = strlen(self::ALPHABET) - 1;
            for ($i = 0; $i < self::LENGTH; $i++) {
                $password .= self::ALPHABET[random_int(0, $max)];
            }

            if (Validator::make(['p' => $password], ['p' => [new StrongPassword]])->passes()) {
                return $password;
            }
        }

        throw new RuntimeException('Impossible de générer un mot de passe conforme.');
    }
}
