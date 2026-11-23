<?php

namespace App\Services\Imports;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** L'aperçu confirmé ne correspond plus au fichier relu (plan T-13 v2, §3) : rien n'est écrit. */
class ImportChangedException extends RuntimeException
{
    public const MESSAGE = 'L’aperçu a changé : vérifiez-le et confirmez à nouveau.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => self::MESSAGE, 'code' => 'preview_changed'], 409);
    }
}
