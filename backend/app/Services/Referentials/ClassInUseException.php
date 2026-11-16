<?php

namespace App\Services\Referentials;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Changement de filière d'une classe utilisée, sans confirmation explicite (décision du 16/11/2026).
 * Rendu en 409 avec le nombre d'apprenants concernés, recalculé par le serveur.
 */
class ClassInUseException extends RuntimeException
{
    public function __construct(public readonly int $learnersCount)
    {
        parent::__construct("{$learnersCount} apprenant(s) changeront de filière.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => "Cette classe compte {$this->learnersCount} apprenant(s) : ils changeront de filière. Confirmez le changement.",
            'code' => 'class_in_use',
            'learners_count' => $this->learnersCount,
        ], 409);
    }
}
