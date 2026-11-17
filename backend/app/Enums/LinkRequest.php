<?php

namespace App\Enums;

/**
 * Origine d'une demande de lien (T-05) : elle décide du lien envoyé et de l'auteur journalisé.
 */
enum LinkRequest: string
{
    /** « Mot de passe oublié » (public) : réinitialisation, ou activation si le compte n'est pas activé (décision C). */
    case Forgot = 'forgot';

    /** « Recevoir un nouveau lien d'activation » (public). */
    case ActivationResend = 'activation_resend';

    /** Envoi par l'administrateur (création ou bouton « Renvoyer le lien », T-10). */
    case ActivationByAdmin = 'activation_admin';

    public function isPublic(): bool
    {
        return $this !== self::ActivationByAdmin;
    }

    /** Libellé de l'origine pour le journal. */
    public function originLabel(): string
    {
        return $this->isPublic() ? 'demande publique' : 'administrateur';
    }
}
