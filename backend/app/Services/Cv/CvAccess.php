<?php

namespace App\Services\Cv;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Learner;
use App\Models\User;

/**
 * Qui peut lire quel CV (plan T-21 v1.2, § 1, décisions 7 et 10) : règle UNIQUE, appelée à l'émission
 * du lien ET au service du fichier.
 *
 * | Lecteur                | Actif | Désactivé | Supprimé |
 * | Administrateur         | oui   | oui       | oui      |
 * | CME, Direction         | oui   | oui       | non      |
 * | Apprenant              | son CV seulement             |
 * | Entreprise             | non en T-21 (T-24)           |
 *
 * `cv.view` seule ne suffit pas : l'entreprise la possède déjà.
 */
final class CvAccess
{
    public function canRead(User $viewer, Learner $learner): bool
    {
        if ($viewer->trashed() || $viewer->isDeactivated() || ! $viewer->can(Permission::CvView->value)) {
            return false;
        }

        return match ($viewer->currentRole()) {
            Role::Administrateur => true,
            Role::Cme, Role::Direction => ! $learner->user->trashed(),
            Role::Apprenant => $learner->user_id === $viewer->id,
            default => false,
        };
    }
}
