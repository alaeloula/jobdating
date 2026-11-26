<?php

namespace App\Services\Imports;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Clé de confirmation déjà utilisée pour un autre aperçu (plan T-64 v1.3, § 6) : 409, rien n'est écrit. */
class ConfirmationKeyReusedException extends RuntimeException
{
    public const MESSAGE = 'Cette confirmation a déjà servi à un autre import : relancez l’aperçu.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => self::MESSAGE, 'code' => 'confirmation_key_reused'], 409);
    }
}
