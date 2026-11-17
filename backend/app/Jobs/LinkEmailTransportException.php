<?php

namespace App\Jobs;

use App\Enums\PasswordTokenType;
use RuntimeException;
use Throwable;

/**
 * Le serveur de messagerie n'a pas accepté l'e-mail d'un lien. Le message ne reprend jamais
 * le contenu de l'e-mail (donc jamais le lien).
 */
class LinkEmailTransportException extends RuntimeException
{
    public function __construct(
        public readonly int $userId,
        public readonly PasswordTokenType $type,
        Throwable $previous,
    ) {
        parent::__construct('Remise de l’e-mail de lien impossible ('.$previous::class.').');
    }
}
