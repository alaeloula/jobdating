<?php

namespace App\Services\Passwords;

use App\Enums\PasswordTokenType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Liens à usage unique (T-05, plan v3, section 2).
 *
 * - Seul le SHA-256 du jeton est stocké ; le jeton en clair est renvoyé à l'appelant, qui
 *   l'envoie aussitôt par e-mail sans l'écrire ailleurs.
 * - Un seul jeton par compte et par type (unicité SQL) : un nouveau lien invalide l'ancien.
 * - Ordre des verrous commun à toutes les opérations : la ligne `users`, puis la ligne du jeton.
 * - Expiré si now() >= expires_at (horloge PHP pour l'écriture et la vérification).
 */
class PasswordTokenService
{
    public const TOKEN_LENGTH = 64;

    private const TABLE = 'password_tokens';

    /**
     * Émet un nouveau lien si le compte est éligible (relu sous verrou), sinon null.
     */
    public function issue(User $user, PasswordTokenType $type): ?string
    {
        return DB::transaction(function () use ($user, $type): ?string {
            $locked = $this->lockUser((int) $user->getKey());

            if ($locked === null || ! PasswordAccess::eligible($locked, $type)) {
                return null;
            }

            $token = Str::random(self::TOKEN_LENGTH);
            $now = Carbon::now();

            DB::table(self::TABLE)->where('user_id', $locked->getKey())->where('type', $type->value)->delete();
            DB::table(self::TABLE)->insert([
                'user_id' => $locked->getKey(),
                'type' => $type->value,
                'token_hash' => self::hash($token),
                'expires_at' => $now->copy()->addMinutes($type->lifetimeMinutes()),
                'created_at' => $now,
            ]);

            return $token;
        });
    }

    /**
     * Compte du lien s'il est valable (sans le consommer), sinon InvalidLinkException.
     */
    public function check(mixed $token, PasswordTokenType $type): User
    {
        $row = $this->findRow($token, $type);
        $user = $row !== null ? User::query()->find($row->user_id) : null;

        if ($row === null || $user === null || self::expired($row->expires_at) || ! PasswordAccess::eligible($user, $type)) {
            throw new InvalidLinkException;
        }

        return $user;
    }

    /**
     * Consomme le lien et applique l'opération, le tout dans une transaction : toute erreur
     * (validation, journal…) annule tout et le lien reste utilisable.
     *
     * @template T
     *
     * @param  callable(User): T  $apply
     * @return T
     */
    public function consume(mixed $token, PasswordTokenType $type, callable $apply): mixed
    {
        $row = $this->findRow($token, $type);

        if ($row === null) {
            throw new InvalidLinkException;
        }

        return DB::transaction(function () use ($token, $type, $row, $apply): mixed {
            $user = $this->lockUser((int) $row->user_id);
            // Relecture sous verrou : une consommation concurrente l'a peut-être déjà supprimé.
            $locked = DB::table(self::TABLE)
                ->where('token_hash', self::hash((string) $token))
                ->where('type', $type->value)
                ->lockForUpdate()
                ->first();

            if ($user === null || $locked === null || (int) $locked->user_id !== (int) $user->getKey()
                || self::expired($locked->expires_at) || ! PasswordAccess::eligible($user, $type)) {
                throw new InvalidLinkException;
            }

            DB::table(self::TABLE)->where('id', $locked->id)->delete();

            return $apply($user);
        });
    }

    /**
     * Annule les liens d'un type (décision E : après tout changement de mot de passe).
     * Contrat pour la réinitialisation par l'administrateur (T-10 à T-12).
     */
    public function revokeAll(User $user, PasswordTokenType $type): void
    {
        DB::table(self::TABLE)->where('user_id', $user->getKey())->where('type', $type->value)->delete();
    }

    /** Purge quotidienne des liens expirés (sans journal). */
    public function purgeExpired(): int
    {
        return DB::table(self::TABLE)->where('expires_at', '<=', Carbon::now())->delete();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Jeton de forme attendue (64 caractères alphanumériques) ; sinon traité comme inconnu. */
    public static function wellFormed(mixed $token): bool
    {
        return is_string($token) && preg_match('/^[A-Za-z0-9]{'.self::TOKEN_LENGTH.'}$/', $token) === 1;
    }

    private function findRow(mixed $token, PasswordTokenType $type): ?object
    {
        if (! self::wellFormed($token)) {
            return null;
        }

        return DB::table(self::TABLE)
            ->where('token_hash', self::hash((string) $token))
            ->where('type', $type->value)
            ->first();
    }

    private function lockUser(int $id): ?User
    {
        return User::query()->whereKey($id)->lockForUpdate()->first();
    }

    private static function expired(mixed $expiresAt): bool
    {
        return Carbon::now()->greaterThanOrEqualTo(Carbon::parse((string) $expiresAt));
    }
}
