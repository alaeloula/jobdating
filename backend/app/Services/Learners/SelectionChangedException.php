<?php

namespace App\Services\Learners;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Action en masse refusée : le nombre d'apprenants concernés a changé depuis la confirmation
 * (plan T-12 v2, §5). Rien n'a été écrit ; l'écran affiche le nouveau nombre et demande une
 * nouvelle confirmation explicite.
 */
class SelectionChangedException extends RuntimeException
{
    public function __construct(public readonly int $eligible, public readonly int $expected)
    {
        parent::__construct("La sélection a changé : {$eligible} apprenant(s) concerné(s) au lieu de {$expected}. Confirmez à nouveau.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'selection_changed',
            'eligible' => $this->eligible,
        ], 409);
    }
}
