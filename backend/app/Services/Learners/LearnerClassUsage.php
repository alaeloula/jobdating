<?php

namespace App\Services\Learners;

use App\Contracts\ClassUsage;
use App\Models\Learner;
use App\Models\Referentials\SchoolClass;

/**
 * Nombre d'apprenants d'une classe (point d'extension T-02, branché par T-12). Compte TOUTES les
 * fiches : actives, désactivées et supprimées logiquement (décision du 19/11/2026) ; leur
 * historique et les statistiques dépendent de la filière de la classe.
 *
 * L'appelant (ReferentialService::update) détient le verrou de la classe ; chaque affectation
 * d'un apprenant à une classe prend le même verrou (LearnerService, puis T-13).
 */
class LearnerClassUsage implements ClassUsage
{
    public function learnersCount(SchoolClass $class): int
    {
        return Learner::query()->where('class_id', $class->getKey())->count();
    }
}
