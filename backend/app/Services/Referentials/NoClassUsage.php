<?php

namespace App\Services\Referentials;

use App\Contracts\ClassUsage;
use App\Models\Referentials\SchoolClass;

/**
 * Implémentation provisoire : aucun apprenant n'existe avant T-12.
 */
class NoClassUsage implements ClassUsage
{
    public function learnersCount(SchoolClass $class): int
    {
        return 0;
    }
}
