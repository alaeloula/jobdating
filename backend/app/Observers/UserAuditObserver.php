<?php

namespace App\Observers;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Journalise la vie des comptes (TRA-01) : création, modification, désactivation,
 * réactivation, suppression et restauration. Les changements de rôle sont journalisés
 * par User::syncRoles() / assignRole().
 */
class UserAuditObserver
{
    /** Champs techniques ou sensibles jamais comparés (connexion, jetons, horodatages). */
    private const IGNORED = ['last_login_at', 'remember_token', 'updated_at', 'created_at', 'deleted_at', 'password'];

    private const TRACKED_ON_CREATE = ['first_name', 'last_name', 'username', 'email', 'phone', 'activated_at', 'deactivated_at'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function created(User $user): void
    {
        $this->audit->record(AuditAction::AccountCreated, $user, new: $this->values($user, self::TRACKED_ON_CREATE));
    }

    public function updated(User $user): void
    {
        // Activation, réinitialisation et changement du mot de passe ont leur propre ligne (T-05).
        if (User::accountAuditMuted()) {
            return;
        }

        $changes = array_diff(array_keys($user->getChanges()), self::IGNORED);

        if ($user->wasChanged('deactivated_at')) {
            $this->audit->record(
                $user->deactivated_at !== null ? AuditAction::AccountDeactivated : AuditAction::AccountReactivated,
                $user,
                ['deactivated_at' => $this->format($user->getOriginal('deactivated_at'))],
                ['deactivated_at' => $this->format($user->deactivated_at)],
            );
            $changes = array_diff($changes, ['deactivated_at']);
        }

        // Le mot de passe n'est jamais enregistré : seul le fait qu'il a changé est noté.
        $passwordChanged = $user->wasChanged('password');

        if ($changes === [] && ! $passwordChanged) {
            return;
        }

        $old = [];
        $new = [];
        foreach ($changes as $field) {
            $old[$field] = $this->format($user->getOriginal($field));
            $new[$field] = $this->format($user->getAttribute($field));
        }
        if ($passwordChanged) {
            // Simple note, sous une clé neutre : une clé « mot de passe » serait filtrée par le service.
            $new['note'] = 'Mot de passe modifié';
        }

        $this->audit->record(AuditAction::AccountUpdated, $user, $old, $new);
    }

    public function deleted(User $user): void
    {
        $this->audit->record(AuditAction::AccountDeleted, $user);
    }

    public function restored(User $user): void
    {
        $this->audit->record(AuditAction::AccountRestored, $user);
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function values(User $user, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $this->format($user->getAttribute($field));
        }

        return $values;
    }

    private function format(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;
    }
}
