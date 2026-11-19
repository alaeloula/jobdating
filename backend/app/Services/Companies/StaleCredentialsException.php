<?php

namespace App\Services\Companies;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Confirmation d'impression d'une fiche dont le mot de passe a été remplacé depuis (plan T-11 v2,
 * constat 1) : la nouvelle fiche doit être imprimée.
 */
class StaleCredentialsException extends RuntimeException
{
    public const MESSAGE = 'Cette fiche n’est plus valable : un nouveau mot de passe a été généré. Imprimez la nouvelle fiche.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => self::MESSAGE, 'code' => 'stale_credentials'], 409);
    }
}
