<?php

namespace App\Services\Accounts;

use App\Enums\AuditAction;
use App\Enums\PasswordTokenType;
use App\Enums\Role;
use App\Models\User;
use App\Notifications\GeneratedPasswordNotification;
use App\Services\AuditLogger;
use App\Services\Auth\SessionRevoker;
use App\Services\Passwords\AccountActivationService;
use App\Services\Passwords\PasswordAccess;
use App\Services\Passwords\PasswordGenerator;
use App\Services\Passwords\PasswordTokenService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Comptes de l'équipe : CME, Direction, administrateur (ADM-01, T-10). Les entreprises (T-11) et
 * les apprenants (T-12) ont leurs propres écrans.
 *
 * Règles :
 * - un seul rôle par compte (RG-ACC-06) ; e-mail unique (RG-USR-01) ;
 * - suppression logique, restaurable (RG-USR-02) ;
 * - protections (RG-USR-03 et décision du 18/11/2026) : on ne peut ni supprimer, ni désactiver,
 *   ni changer le rôle de son propre compte ; il reste toujours au moins un administrateur actif.
 *   Les lignes des administrateurs sont verrouillées pendant le contrôle (pas de course) ;
 * - désactivation et suppression : toutes les sessions fermées (RG-ACC-05), liens annulés.
 */
class StaffAccountService
{
    /** @var list<Role> */
    public const ROLES = [Role::Administrateur, Role::Cme, Role::Direction];

    /**
     * Essais d'une transaction victime d'un interblocage MySQL (deux administrateurs qui agissent
     * l'un sur l'autre au même instant) : Laravel la rejoue, et la règle est réévaluée sur l'état
     * relu (revue Codex T-10, constat 2).
     */
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly AccountActivationService $activation,
        private readonly SessionRevoker $sessions,
        private readonly PasswordTokenService $tokens,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Comptes de l'équipe, supprimés compris (périmètre de l'écran).
     *
     * @return Builder<User>
     */
    public static function query(): Builder
    {
        return User::withTrashed()->whereHas(
            'roles',
            fn (Builder $q) => $q->whereIn('name', array_map(fn (Role $r) => $r->value, self::ROLES)),
        );
    }

    /**
     * Création : compte non activé, mot de passe inutilisable, lien d'activation envoyé après la
     * validation de la transaction (AUTH-02).
     *
     * @param  array{first_name: string, last_name: string, email: string, phone?: string|null, role: string}  $data
     */
    public function create(array $data, User $admin): User
    {
        return DB::transaction(function () use ($data, $admin): User {
            $user = new User;
            $user->fill([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                // Personne ne connaît ce mot de passe : l'accès passe par le lien d'activation.
                'password' => Hash::make(Str::random(64)),
            ]);
            $user->save();
            $user->setRole(Role::from($data['role']));

            $this->activation->sendLink($user, $admin);

            return $user;
        }, self::ATTEMPTS);
    }

    /**
     * @param  array{first_name?: string, last_name?: string, email?: string, phone?: string|null, role?: string}  $data
     */
    public function update(User $target, array $data, User $admin): User
    {
        return DB::transaction(function () use ($target, $data, $admin): User {
            $user = $this->lock($target);
            $role = isset($data['role']) ? Role::from($data['role']) : null;

            if ($role !== null && $role !== $user->currentRole()) {
                $this->guardSelf($user, $admin, 'changer le rôle de');
                $this->guardLastAdmin($user);
            }

            $user->fill(array_intersect_key($data, array_flip(['first_name', 'last_name', 'email', 'phone'])));
            $user->save();

            if ($role !== null && $role !== $user->currentRole()) {
                $user->setRole($role);
            }

            return $user;
        }, self::ATTEMPTS);
    }

    public function deactivate(User $target, User $admin): User
    {
        return DB::transaction(function () use ($target, $admin): User {
            $user = $this->lock($target);
            $this->guardSelf($user, $admin, 'désactiver');
            $this->guardLastAdmin($user);

            if (! $user->isDeactivated()) {
                $user->forceFill(['deactivated_at' => now()])->save();
            }
            $this->cut($user);

            return $user;
        }, self::ATTEMPTS);
    }

    public function reactivate(User $target): User
    {
        return DB::transaction(function () use ($target): User {
            $user = $this->lock($target);
            if ($user->isDeactivated()) {
                $user->forceFill(['deactivated_at' => null])->save();
            }

            return $user;
        }, self::ATTEMPTS);
    }

    public function delete(User $target, User $admin): void
    {
        DB::transaction(function () use ($target, $admin): void {
            $user = $this->lock($target);
            $this->guardSelf($user, $admin, 'supprimer');
            $this->guardLastAdmin($user);

            $user->delete();
            $this->cut($user);
        }, self::ATTEMPTS);
    }

    public function restore(User $target): User
    {
        return DB::transaction(function () use ($target): User {
            $user = $this->lock($target, allowDeleted: true);
            if ($user->trashed()) {
                $user->restore();
            }

            return $user;
        }, self::ATTEMPTS);
    }

    /** Nouveau lien d'activation (compte pas encore activé). */
    public function resendActivation(User $target, User $admin): void
    {
        if ($target->trashed()) {
            throw AccountRuleException::deleted();
        }

        try {
            $this->activation->sendLink($target, $admin);
        } catch (DomainException) {
            throw new AccountRuleException('not_activatable', 'Ce compte est déjà activé ou désactivé : aucun lien à envoyer.');
        }
    }

    /**
     * Réinitialisation par l'administrateur (décision du 14/11/2026) : mot de passe généré, renvoyé
     * à l'appelant pour un affichage UNIQUE et envoyé par e-mail ; liens annulés ; sessions du compte
     * fermées (pour son propre compte : les autres sessions, la courante est conservée).
     */
    public function resetPassword(User $target, User $admin, string $currentSessionId): string
    {
        $password = PasswordGenerator::generate();

        DB::transaction(function () use ($target, $admin, $currentSessionId, $password): void {
            $user = $this->lock($target);

            if (! PasswordAccess::canReset($user)) {
                throw new AccountRuleException(
                    'not_resettable',
                    $user->isActivated()
                        ? 'Ce compte est désactivé : réactivez-le d’abord.'
                        : 'Ce compte n’est pas encore activé : renvoyez plutôt le lien d’activation.',
                );
            }

            User::withoutAccountAudit(fn () => $user->forceFill(['password' => $password])->save());
            $this->tokens->revokeAll($user, PasswordTokenType::Reset);

            if ($user->is($admin)) {
                $this->sessions->closeOthers($user, $currentSessionId);
            } else {
                $this->sessions->closeAll($user);
            }

            $this->audit->record(AuditAction::PasswordReset, $user, new: ['note' => 'Réinitialisé par l’administrateur'], actor: $admin);
            $user->notify(new GeneratedPasswordNotification($password));

            if ($user->is($admin)) {
                $admin->setRawAttributes($user->getAttributes(), true);
            }
        }, self::ATTEMPTS);

        return $password;
    }

    /** Ligne du compte verrouillée et relue (les règles portent sur l'état en base). */
    private function lock(User $target, bool $allowDeleted = false): User
    {
        $user = User::withTrashed()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

        if ($user->trashed() && ! $allowDeleted) {
            throw AccountRuleException::deleted();
        }

        return $user;
    }

    private function guardSelf(User $user, User $admin, string $action): void
    {
        if ($user->is($admin)) {
            throw AccountRuleException::self($action);
        }
    }

    /**
     * L'action retire au compte sa capacité d'administrateur : il doit rester au moins un autre
     * administrateur actif. Les administrateurs sont verrouillés pendant le contrôle.
     */
    private function guardLastAdmin(User $user): void
    {
        // Seul un administrateur réellement connectable compte : activé, non désactivé, non supprimé.
        // Une invitation d'administrateur n'est pas encore un administrateur actif (revue Codex T-10, constat 1).
        if ($user->currentRole() !== Role::Administrateur || $user->isDeactivated() || ! $user->isActivated()) {
            return;
        }

        $others = User::role(Role::Administrateur->value)
            ->whereNull('deactivated_at')
            ->whereNotNull('activated_at')
            ->whereKeyNot($user->getKey())
            ->lockForUpdate()
            ->count();

        if ($others === 0) {
            throw AccountRuleException::lastAdmin();
        }
    }

    /** Coupe l'accès : sessions fermées (RG-ACC-05) et liens annulés. */
    private function cut(User $user): void
    {
        $this->sessions->closeAll($user);
        $this->tokens->revokeAll($user, PasswordTokenType::Reset);
        $this->tokens->revokeAll($user, PasswordTokenType::Activation);
    }
}
