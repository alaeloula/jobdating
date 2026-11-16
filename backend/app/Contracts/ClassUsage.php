<?php

namespace App\Contracts;

use App\Models\Referentials\SchoolClass;

/**
 * Point d'extension (T-02) : nombre d'apprenants rattachés à une classe.
 *
 * Tant que la table des apprenants n'existe pas, l'implémentation par défaut répond 0.
 * T-12 DOIT remplacer cette implémentation (liaison dans AppServiceProvider).
 *
 * Protocole de concurrence : l'appelant détient un verrou FOR UPDATE sur la ligne de la classe.
 * T-12 et T-13 DOIVENT prendre le même verrou sur la classe lors de la première affectation
 * d'un apprenant à cette classe.
 */
interface ClassUsage
{
    public function learnersCount(SchoolClass $class): int;
}
