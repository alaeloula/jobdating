<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Nouveau mot de passe généré par l'administrateur, envoyé en clair par e-mail (décision du
 * 14/11/2026, risque accepté). Mise en file CHIFFRÉE (le mot de passe ne figure jamais en clair
 * dans les tables jobs / failed_jobs), après la validation de la transaction.
 */
class GeneratedPasswordNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] private readonly string $password)
    {
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

        return (new MailMessage)
            ->subject('Votre nouveau mot de passe JobDating')
            ->greeting("Bonjour {$firstName},")
            ->line('L’administration a réinitialisé votre mot de passe JobDating. Votre nouveau mot de passe est :')
            ->line($this->password)
            ->action('Se connecter', config('app.frontend_url').'/login')
            ->line('Vous pouvez le changer à tout moment depuis « Mon profil ». Vos sessions ouvertes ont été fermées.')
            ->salutation('L’équipe JobDating');
    }
}
