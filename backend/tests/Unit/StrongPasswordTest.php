<?php

namespace Tests\Unit;

use App\Rules\StrongPassword;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Politique de mot de passe (RG-AUTH-07, décision du 17/11/2026).
 */
class StrongPasswordTest extends TestCase
{
    protected function tearDown(): void
    {
        StrongPassword::flushCache();
        parent::tearDown();
    }

    private function errors(mixed $password): array
    {
        return Validator::make(['password' => $password], ['password' => [new StrongPassword]])
            ->errors()->get('password');
    }

    /**
     * @return iterable<string, array{mixed, string|null}>
     */
    public static function cases(): iterable
    {
        yield '9 caractères' => ['Kq7#mZ2vR', '10 caractères minimum.'];
        yield '10 caractères' => ['Kq7#mZ2vRt', null];
        yield '64 caractères' => [str_repeat('Kq7#mZ2v', 8), null];
        yield '65 caractères' => [str_repeat('Kq7#mZ2v', 8).'x', 'Le mot de passe ne doit pas dépasser 64 caractères.'];
        // 40 caractères accentués = 80 octets : sous 64 caractères mais au-delà de la limite de bcrypt.
        yield '72 octets dépassés' => [str_repeat('é', 40), 'Ce mot de passe est trop long.'];
        // 36 caractères accentués = 72 octets : accepté.
        yield '72 octets exactement' => [str_repeat('é', 36), null];
        yield '73 octets' => [str_repeat('é', 36).'x', 'Ce mot de passe est trop long.'];
        // Les caractères Unicode comptent pour un caractère chacun (4 octets par emoji).
        yield 'emoji, 10 caractères' => [str_repeat('🔑', 10), null];
        yield 'emoji, 9 caractères' => [str_repeat('🔑', 9), '10 caractères minimum.'];
        yield 'mot courant' => ['1234567890', 'Ce mot de passe est trop courant. Choisissez-en un autre.'];
        yield 'mot courant, majuscules' => ['QWERTYUIOP', 'Ce mot de passe est trop courant. Choisissez-en un autre.'];
        yield 'mot courant, espaces autour' => ['  1234567890  ', 'Ce mot de passe est trop courant. Choisissez-en un autre.'];
        yield 'non textuel' => [['x'], 'Le mot de passe doit être un texte.'];
    }

    #[DataProvider('cases')]
    public function test_regle(mixed $password, ?string $expected): void
    {
        $errors = $this->errors($password);

        if ($expected === null) {
            $this->assertSame([], $errors);
        } else {
            $this->assertSame([$expected], $errors);
        }
    }

    public function test_la_liste_contient_les_mots_attendus_et_ignore_l_en_tete(): void
    {
        $this->assertSame([], $this->errors('# Mots de passe courants refusés'));
        $this->assertNotSame([], $this->errors('qwertyuiop'));
    }

    public function test_la_liste_est_chargee_une_seule_fois(): void
    {
        StrongPassword::flushCache();
        $this->errors('Kq7#mZ2vRt');
        $this->assertTrue(StrongPassword::isLoaded());

        // Fichier rendu introuvable : la liste déjà chargée sert encore (aucune nouvelle lecture).
        config(['security.common_passwords' => base_path('introuvable.txt')]);
        $this->assertSame([], $this->errors('Kq7#mZ2vRt'));
    }

    public function test_liste_absente_erreur_et_jamais_de_validation_silencieuse(): void
    {
        StrongPassword::flushCache();
        config(['security.common_passwords' => base_path('introuvable.txt')]);

        $this->expectException(RuntimeException::class);
        $this->errors('Kq7#mZ2vRt');
    }
}
