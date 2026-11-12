<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable {
        HasRoles::assignRole as private attachRoles;
        HasRoles::syncRoles as private replaceRoles;
    }

    private const SINGLE_ROLE_MESSAGE = 'Un compte a un seul rôle (RG-ACC-06) : utiliser setRole().';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Donne au compte son rôle, en remplaçant le précédent (RG-ACC-06).
     */
    public function setRole(Role $role): static
    {
        return $this->syncRoles($role->value);
    }

    /**
     * Rôle du compte, ou null s'il n'en a pas encore.
     */
    public function currentRole(): ?Role
    {
        $name = $this->getRoleNames()->first();

        return is_string($name) ? Role::tryFrom($name) : null;
    }

    /**
     * Remplace les rôles du compte (RG-ACC-06).
     *
     * Le nombre de rôles demandés est vérifié avant de retirer le rôle actuel :
     * une demande refusée laisse le compte inchangé.
     *
     * @param  mixed  ...$roles
     */
    public function syncRoles(...$roles): static
    {
        $this->ensurePersistedForRoles();

        if (count($this->collectRoles($roles)) > 1) {
            throw new LogicException(self::SINGLE_ROLE_MESSAGE);
        }

        return DB::transaction(function () use ($roles): static {
            $this->lockForRoleChange();

            return $this->replaceRoles(...$roles);
        });
    }

    /**
     * Ajout de rôle limité à un seul rôle par compte (RG-ACC-06).
     *
     * Utiliser setRole() pour changer de rôle. La contrainte d'unicité de la table
     * model_has_roles garantit la règle même en cas d'opérations concurrentes.
     *
     * @param  mixed  ...$roles
     */
    public function assignRole(...$roles): static
    {
        $this->ensurePersistedForRoles();

        $requested = $this->collectRoles($roles);

        return DB::transaction(function () use ($roles, $requested): static {
            $this->lockForRoleChange();

            $current = $this->roles()->pluck('id')->all();

            if (count(array_unique(array_merge($current, $requested))) > 1) {
                throw new LogicException(self::SINGLE_ROLE_MESSAGE);
            }

            return $this->attachRoles(...$roles);
        });
    }

    /**
     * Un rôle ne s'attribue qu'à un compte enregistré : sinon Spatie diffère
     * l'insertion et plusieurs rôles pourraient s'accumuler avant l'enregistrement.
     */
    private function ensurePersistedForRoles(): void
    {
        if (! $this->exists) {
            throw new LogicException('Enregistrer le compte avant de lui attribuer un rôle (RG-ACC-06).');
        }
    }

    /**
     * Sérialise les changements de rôle d'un même compte.
     */
    private function lockForRoleChange(): void
    {
        static::query()->whereKey($this->getKey())->lockForUpdate()->first();
    }
}
