<?php

namespace App\Services\Learners;

use RuntimeException;

/**
 * La classe de l'apprenant a changé pendant l'attente des verrous : l'opération recommence avec
 * les bonnes classes (ordre de verrouillage, plan T-12 v2.1). Usage interne à LearnerService.
 */
class ClassMovedException extends RuntimeException {}
