<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mot de passe généré par l'administrateur, envoyé en clair par e-mail (décision du 14/11/2026,
 * risque accepté). Mise en file CHIFFRÉE (le mot de passe ne figure jamais en clair dans les tables
 * jobs / failed_jobs), après la validation FINALE de la transaction (afterCommit).
 *
 * Deux motifs (plan T-12 v2, constat 6) : création d'un compte apprenant, ou réinitialisation.
 */
class GeneratedPasswordNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public const REASON_RESET = 'reset';

    public const REASON_CREATED = 'created';

    public function __construct(
        #[\SensitiveParameter] private readonly string $password,
        public readonly string $reason = self::REASON_RESET,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = (string) ($notifiable->first_name ?? '');
        $created = $this->reason === self::REASON_CREATED;

        $mail = (new MailMessage)
            ->subject($created ? 'Votre compte JobDating' : 'Votre nouveau mot de passe JobDating')
            ->greeting("Bonjour {$firstName},")
            ->line($created
                ? 'Votre compte JobDating a été créé. Connectez-vous avec votre e-mail professionnel et ce mot de passe :'
                : 'L’administration a réinitialisé votre mot de passe JobDating. Votre nouveau mot de passe est :')
            ->line($this->password)
            ->action('Se connecter', config('app.frontend_url').'/login');

        return $mail
            ->line($created
                ? 'Vous pouvez le changer à tout moment depuis « Mon profil ».'
                : 'Vous pouvez le changer à tout moment depuis « Mon profil ». Vos sessions ouvertes ont été fermées.')
            ->salutation('L’équipe JobDating');
    }
}
