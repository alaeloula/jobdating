<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Observers\UserAuditObserver;
use App\Services\AuditLogger;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\Traits\HasRoles;

#[ObservedBy([UserAuditObserver::class])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes {
        HasRoles::assignRole as private attachRoles;
        HasRoles::syncRoles as private replaceRoles;
    }

    private const SINGLE_ROLE_MESSAGE = 'Un compte a un seul rôle (RG-ACC-06) : utiliser setRole().';

    /** Vrai pendant un remplacement de rôle (syncRoles), pour éviter un double journal. */
    private bool $replacingRoles = false;

    /** Profondeur des opérations qui journalisent elles-mêmes la modification du compte. */
    private static int $accountAuditMuted = 0;

    /**
     * Exécute une opération qui écrit sa propre ligne de journal (activation, réinitialisation,
     * changement du mot de passe) : l'observateur n'écrit pas « Compte modifié » en double.
     * Limité à l'opération, rétabli même en cas d'exception.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public static function withoutAccountAudit(callable $operation): mixed
    {
        self::$accountAuditMuted++;
        try {
            return $operation();
        } finally {
            self::$accountAuditMuted--;
        }
    }

    public static function accountAuditMuted(): bool
    {
        return self::$accountAuditMuted > 0;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'username',
        'email',
        'phone',
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
            'password' => 'hashed',
            'activated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * Compte désigné par l'identifiant de connexion : e-mail ou nom d'utilisateur (AUTH-01).
     *
     * La comparaison suit la collation MySQL (casse et accents ignorés). Un compte supprimé
     * (suppression logique) n'est pas retrouvé : il est traité comme inexistant.
     */
    public static function findForLogin(string $identifier): ?self
    {
        if ($identifier === '') {
            return null;
        }

        return static::query()
            ->where(fn ($query) => $query->where('email', $identifier)->orWhere('username', $identifier))
            ->first();
    }

    /**
     * Compte activé (AUTH-02) : sans activation, la connexion est refusée.
     */
    public function isActivated(): bool
    {
        return $this->activated_at !== null;
    }

    /**
     * Compte désactivé : connexion refusée et sessions perdues (RG-ACC-05).
     */
    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
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
            $before = $this->roleNameFromDatabase();

            // Spatie remplace le rôle en passant par assignRole() : ce changement n'est
            // journalisé qu'une fois, ici.
            $this->replacingRoles = true;
            try {
                $result = $this->replaceRoles(...$roles);
            } finally {
                $this->replacingRoles = false;
            }
            $this->recordRoleChange($before);

            return $result;
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

            $before = $this->roleNameFromDatabase();
            $result = $this->attachRoles(...$roles);
            if (! $this->replacingRoles) {
                $this->recordRoleChange($before);
            }

            return $result;
        });
    }

    /**
     * Rôle actuellement enregistré en base (sans le cache de la relation).
     */
    private function roleNameFromDatabase(): ?string
    {
        $name = $this->roles()->value('name');

        return is_string($name) ? $name : null;
    }

    /**
     * Journalise un changement de rôle (TRA-01), dans la même transaction que le changement.
     */
    private function recordRoleChange(?string $before): void
    {
        $after = $this->roleNameFromDatabase();

        if ($after !== $before) {
            app(AuditLogger::class)->record(AuditAction::RoleChanged, $this, ['role' => $before], ['role' => $after]);
        }
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
