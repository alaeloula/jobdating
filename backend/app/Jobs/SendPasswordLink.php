<?php

namespace App\Jobs;

use App\Enums\LinkRequest;
use App\Enums\PasswordTokenType;
use App\Models\User;
use App\Notifications\PasswordLinkNotification;
use App\Services\Passwords\PasswordAccess;
use App\Services\Passwords\PasswordTokenService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Envoi d'un lien d'activation ou de réinitialisation (T-05, plan v3, sections 4 et 10).
 *
 * Mise en file pour TOUTE demande publique valide, que le compte existe ou non : la requête
 * fait donc le même travail pour toutes les adresses (RG-AUTH-09). C'est ici, en arrière-plan,
 * que le compte est cherché et que l'éligibilité est décidée.
 *
 * Étapes et reprises :
 * 1. éligibilité et émission du jeton (transaction validée) ;
 * 2. remise de l'e-mail au serveur de messagerie (sendNow) : un échec de transport fait reprendre
 *    la tâche avec un NOUVEAU jeton (le précédent n'a été remis à personne). Après la dernière
 *    tentative : journal « échec de remise » ;
 * 3. après une remise réussie, la tâche se termine TOUJOURS avec succès (jamais de second envoi) ;
 *    le journal « lien remis » est confié à la tâche RecordLinkSent, reprise seule si besoin.
 *
 * Contenu chiffré dans la file (adresse demandée) ; le jeton n'y est jamais.
 */
class SendPasswordLink implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly LinkRequest $request,
        public readonly ?string $email = null,
        public readonly ?int $userId = null,
        public readonly ?int $adminId = null,
    ) {}

    /** Demande publique : seule l'adresse saisie est transmise (aucune lecture de compte dans la requête). */
    public static function forPublicRequest(LinkRequest $request, string $email): self
    {
        return new self($request, email: $email);
    }

    /** Envoi par l'administrateur (T-10). */
    public static function forAdmin(User $user, User $admin): self
    {
        return new self(LinkRequest::ActivationByAdmin, userId: (int) $user->getKey(), adminId: (int) $admin->getKey());
    }

    public function handle(PasswordTokenService $tokens): void
    {
        $user = $this->resolveUser();
        $type = $user !== null ? $this->linkType($user) : null;

        // Compte inconnu, supprimé, désactivé, entreprise, sans rôle… : rien, sans journal.
        if ($user === null || $type === null) {
            return;
        }

        $token = $tokens->issue($user, $type);
        if ($token === null) {
            return;
        }

        try {
            Notification::sendNow($user, new PasswordLinkNotification($type, $token));
        } catch (Throwable $e) {
            throw new LinkEmailTransportException((int) $user->getKey(), $type, $e);
        }

        // Remise réussie : plus jamais de nouvel envoi pour cette demande, même si le journal échoue.
        try {
            RecordLinkSent::dispatch((int) $user->getKey(), $type, $this->request, $this->adminId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Après la dernière tentative : seul un échec de remise est journalisé comme tel.
     */
    public function failed(Throwable $exception): void
    {
        if ($exception instanceof LinkEmailTransportException) {
            LinkAudit::recordFailure($exception->userId, $exception->type, $this->request, $this->adminId);
        }
    }

    private function resolveUser(): ?User
    {
        if ($this->userId !== null) {
            return User::query()->find($this->userId);
        }

        if ($this->email === null || $this->email === '') {
            return null;
        }

        // Même comparaison que la connexion : collation MySQL de la colonne email.
        return User::query()->where('email', $this->email)->first();
    }

    private function linkType(User $user): ?PasswordTokenType
    {
        return match ($this->request) {
            LinkRequest::Forgot => match (true) {
                PasswordAccess::canReset($user) => PasswordTokenType::Reset,
                // Décision C : compte non activé → nouveau lien d'activation.
                PasswordAccess::canActivate($user) => PasswordTokenType::Activation,
                default => null,
            },
            LinkRequest::ActivationResend, LinkRequest::ActivationByAdmin => PasswordAccess::canActivate($user)
                ? PasswordTokenType::Activation
                : null,
        };
    }
}
