<?php

namespace App\Services\Passwords;

use App\Jobs\SendPasswordLink;
use App\Models\User;
use DomainException;

/**
 * Envoi du lien d'activation par l'administrateur (AUTH-02, contrat pour T-10 : création d'un
 * compte CME, Direction ou administrateur, et bouton « Renvoyer le lien »).
 *
 * À appeler APRÈS l'attribution du rôle. La tâche n'est mise en file qu'après la validation de
 * la transaction de l'appelant (afterCommit explicite), pour ne jamais envoyer un lien vers un
 * compte qui ne serait finalement pas enregistré.
 */
class AccountActivationService
{
    public function sendLink(User $user, User $admin): void
    {
        if (! PasswordAccess::canActivate($user)) {
            throw new DomainException('Ce compte ne peut pas recevoir de lien d’activation.');
        }

        dispatch(SendPasswordLink::forAdmin($user, $admin))->afterCommit();
    }
}
