<?php

namespace Tests\Feature\Passwords;

use App\Enums\PasswordTokenType;
use App\Enums\Role;
use App\Models\User;
use App\Services\Passwords\InvalidLinkException;
use App\Services\Passwords\PasswordTokenService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Liens à usage unique (T-05, plan v3 section 2).
 */
class PasswordTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private PasswordTokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->tokens = app(PasswordTokenService::class);
        Carbon::setTestNow('2026-11-17 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function learner(): User
    {
        return User::factory()->withRole(Role::Apprenant)->create();
    }

    private function unactivatedCme(): User
    {
        return User::factory()->unactivated()->withRole(Role::Cme)->create();
    }

    public function test_seul_le_hachage_est_stocke_et_un_nouveau_lien_invalide_l_ancien(): void
    {
        $user = $this->learner();

        $first = $this->tokens->issue($user, PasswordTokenType::Reset);
        $second = $this->tokens->issue($user, PasswordTokenType::Reset);

        $this->assertIsString($first);
        $this->assertSame(64, strlen((string) $second));
        $this->assertSame(1, DB::table('password_tokens')->where('user_id', $user->id)->count());
        $this->assertDatabaseMissing('password_tokens', ['token_hash' => $second]);
        $this->assertDatabaseHas('password_tokens', ['token_hash' => hash('sha256', (string) $second)]);

        $this->expectException(InvalidLinkException::class);
        $this->tokens->check($first, PasswordTokenType::Reset);
    }

    public function test_unicite_sql_un_lien_par_compte_et_par_type(): void
    {
        $user = $this->learner();
        $row = ['user_id' => $user->id, 'type' => 'reset', 'expires_at' => now()->addHour(), 'created_at' => now()];
        DB::table('password_tokens')->insert($row + ['token_hash' => str_repeat('a', 64)]);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('password_tokens')->insert($row + ['token_hash' => str_repeat('b', 64)]);
    }

    /**
     * @return iterable<string, array{PasswordTokenType, int, bool}>
     */
    public static function bounds(): iterable
    {
        yield 'réinitialisation, 1 s avant 60 min' => [PasswordTokenType::Reset, 60 * 60 - 1, true];
        yield 'réinitialisation, à 60 min exactement' => [PasswordTokenType::Reset, 60 * 60, false];
        yield 'réinitialisation, après 60 min' => [PasswordTokenType::Reset, 60 * 60 + 1, false];
        yield 'activation, 1 s avant 48 h' => [PasswordTokenType::Activation, 48 * 3600 - 1, true];
        yield 'activation, à 48 h exactement' => [PasswordTokenType::Activation, 48 * 3600, false];
        yield 'activation, après 48 h' => [PasswordTokenType::Activation, 48 * 3600 + 1, false];
    }

    #[DataProvider('bounds')]
    public function test_expiration_aux_bornes(PasswordTokenType $type, int $seconds, bool $valid): void
    {
        $user = $type === PasswordTokenType::Reset ? $this->learner() : $this->unactivatedCme();
        $token = $this->tokens->issue($user, $type);

        Carbon::setTestNow(now()->addSeconds($seconds));

        if (! $valid) {
            $this->expectException(InvalidLinkException::class);
        }
        $this->assertSame($user->id, $this->tokens->check($token, $type)->id);
    }

    public function test_mauvais_type_mal_forme_inconnu_et_double_usage(): void
    {
        $user = $this->learner();
        $token = (string) $this->tokens->issue($user, PasswordTokenType::Reset);

        foreach ([[$token, PasswordTokenType::Activation], ['court', PasswordTokenType::Reset], [str_repeat('Z', 64), PasswordTokenType::Reset], [['x'], PasswordTokenType::Reset], [null, PasswordTokenType::Reset]] as [$value, $type]) {
            try {
                $this->tokens->check($value, $type);
                $this->fail('Lien accepté à tort.');
            } catch (InvalidLinkException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->tokens->consume($token, PasswordTokenType::Reset, fn (User $u) => null);

        $this->expectException(InvalidLinkException::class);
        $this->tokens->consume($token, PasswordTokenType::Reset, fn (User $u) => null);
    }

    public function test_compte_devenu_ineligible_entre_la_verification_et_la_validation(): void
    {
        foreach (['deactivated_at' => now(), 'deleted' => true, 'role' => Role::Entreprise] as $change => $value) {
            $user = $this->learner();
            $token = (string) $this->tokens->issue($user, PasswordTokenType::Reset);
            $this->tokens->check($token, PasswordTokenType::Reset);

            match ($change) {
                'deactivated_at' => $user->forceFill(['deactivated_at' => $value])->save(),
                'deleted' => $user->delete(),
                'role' => $user->setRole($value),
            };

            try {
                $this->tokens->consume($token, PasswordTokenType::Reset, fn (User $u) => null);
                $this->fail("Lien accepté après : {$change}.");
            } catch (InvalidLinkException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_une_erreur_pendant_l_operation_annule_tout_et_garde_le_lien(): void
    {
        $user = $this->learner();
        $token = (string) $this->tokens->issue($user, PasswordTokenType::Reset);

        try {
            $this->tokens->consume($token, PasswordTokenType::Reset, function (User $u): void {
                $u->forceFill(['first_name' => 'Modifié'])->save();
                throw new RuntimeException('journal indisponible');
            });
        } catch (RuntimeException) {
        }

        $this->assertNotSame('Modifié', $user->fresh()?->first_name);
        $this->assertSame($user->id, $this->tokens->check($token, PasswordTokenType::Reset)->id);
    }

    public function test_aucun_lien_pour_un_compte_ineligible(): void
    {
        $this->assertNull($this->tokens->issue(User::factory()->withRole(Role::Entreprise)->create(), PasswordTokenType::Reset));
        $this->assertNull($this->tokens->issue(User::factory()->withRole(Role::Apprenant)->create(), PasswordTokenType::Activation));
        $this->assertNull($this->tokens->issue(User::factory()->deactivated()->withRole(Role::Cme)->create(), PasswordTokenType::Reset));
        $this->assertNull($this->tokens->issue($this->unactivatedCme(), PasswordTokenType::Reset));
        $this->assertNull($this->tokens->issue(User::factory()->withRole(Role::Cme)->create(), PasswordTokenType::Activation));
        $this->assertSame(0, DB::table('password_tokens')->count());
    }

    public function test_revocation_et_purge_des_liens_expires(): void
    {
        $a = $this->learner();
        $b = $this->learner();
        $this->tokens->issue($a, PasswordTokenType::Reset);
        $this->tokens->issue($b, PasswordTokenType::Reset);

        $this->tokens->revokeAll($a, PasswordTokenType::Reset);
        $this->assertSame(0, DB::table('password_tokens')->where('user_id', $a->id)->count());

        Carbon::setTestNow(now()->addHour());
        $this->assertSame(1, $this->tokens->purgeExpired());
        $this->assertSame(0, DB::table('password_tokens')->count());
    }
}
