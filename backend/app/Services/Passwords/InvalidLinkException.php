<?php

namespace App\Services\Passwords;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Lien expiré, déjà utilisé, inconnu ou mal formé, ou compte devenu inéligible : une seule
 * réponse pour tous ces cas (410), qui ne dit pas lequel.
 */
class InvalidLinkException extends RuntimeException
{
    public const MESSAGE = 'Ce lien n’est plus valable. Il a expiré ou a déjà été utilisé.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function render(): JsonResponse
    {
        return response()
            ->json(['message' => self::MESSAGE, 'code' => 'link_invalid'], 410)
            ->header('Cache-Control', 'no-store');
    }
}
