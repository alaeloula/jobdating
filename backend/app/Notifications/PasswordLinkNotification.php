<?php

namespace App\Notifications;

use App\Enums\PasswordTokenType;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail d'un lien d'activation (AUTH-02) ou de réinitialisation (AUTH-03).
 *
 * Jamais mis en file : il est envoyé aussitôt (sendNow) par la tâche SendPasswordLink, qui
 * vient de créer le jeton. Le jeton n'apparaît donc jamais dans la file d'attente.
 */
class PasswordLinkNotification extends Notification
{
    public function __construct(
        private readonly PasswordTokenType $type,
        #[\SensitiveParameter] private readonly string $token,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        $path = $this->type === PasswordTokenType::Activation ? '/activation' : '/reinitialiser';

        // Jeton après « # » : ni envoyé au serveur, ni dans le Referer, ni dans les journaux d'accès.
        return config('app.frontend_url').$path.'#'.$this->token;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = (string) ($notifiable->first_name ?? '');

        return $this->type === PasswordTokenType::Activation
            ? (new MailMessage)
                ->subject('Activez votre compte JobDating')
                ->greeting("Bonjour {$firstName},")
                ->line('Un compte JobDating vient d’être créé pour vous. Choisissez votre mot de passe pour l’activer.')
                ->action('Activer mon compte', $this->url())
                ->line('Ce lien est valable 48 heures et ne fonctionne qu’une fois.')
                ->salutation('L’équipe JobDating')
            : (new MailMessage)
                ->subject('Réinitialisation de votre mot de passe JobDating')
                ->greeting("Bonjour {$firstName},")
                ->line('Vous avez demandé à réinitialiser votre mot de passe JobDating.')
                ->action('Choisir un nouveau mot de passe', $this->url())
                ->line('Ce lien est valable 60 minutes et ne fonctionne qu’une fois.')
                ->line('Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail : votre mot de passe ne change pas.')
                ->salutation('L’équipe JobDating');
    }
}
