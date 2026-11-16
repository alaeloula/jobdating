<?php

namespace App\Contracts;

use App\Models\Referentials\EvaluationTag;

/**
 * Point d'extension (T-02) : l'étiquette est-elle utilisée par au moins une évaluation ?
 *
 * Tant que les évaluations n'existent pas, l'implémentation par défaut répond « non ».
 * T-31 DOIT remplacer cette implémentation (liaison dans AppServiceProvider).
 *
 * Protocole de concurrence : l'appelant détient un verrou FOR UPDATE sur la ligne de l'étiquette.
 * T-31 DOIT prendre le même verrou lorsqu'il associe l'étiquette à une évaluation.
 */
interface TagUsage
{
    public function isUsed(EvaluationTag $tag): bool;
}
