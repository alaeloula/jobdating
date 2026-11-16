<?php

namespace App\Services\Referentials;

use App\Contracts\TagUsage;
use App\Models\Referentials\EvaluationTag;

/**
 * Implémentation provisoire : aucune évaluation n'existe avant T-31.
 */
class NoTagUsage implements TagUsage
{
    public function isUsed(EvaluationTag $tag): bool
    {
        return false;
    }
}
