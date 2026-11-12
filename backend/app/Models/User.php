<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use LogicException;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable {
        HasRoles::assignRole as private attachRoles;
    }

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
     * Ajout de rôle limité à un seul rôle par compte (RG-ACC-06).
     *
     * Utiliser setRole() pour changer de rôle. syncRoles() reste possible :
     * il retire les rôles existants avant d'appeler cette méthode.
     *
     * @param  mixed  ...$roles
     */
    public function assignRole(...$roles): static
    {
        $requested = $this->collectRoles($roles);
        $current = $this->exists ? $this->roles()->pluck('id')->all() : [];

        if (count(array_unique(array_merge($current, $requested))) > 1) {
            throw new LogicException('Un compte a un seul rôle (RG-ACC-06) : utiliser setRole().');
        }

        return $this->attachRoles(...$roles);
    }
}
