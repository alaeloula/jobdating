<?php

namespace App\Services\Imports;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Fichier refusé en entier (ADM-04, exceptions) : vide, illisible, en-têtes manquants, limites
 * dépassées. Message explicite, jamais de valeur lue dans le fichier.
 */
class ImportFileException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'errors' => ['file' => [$this->getMessage()]]], 422);
    }
}
