<?php

namespace App\Contracts;

use App\Models\Company;
use App\Models\Event;
use App\Models\Learner;

/**
 * Point d'extension (T-14) : passages d'un événement. Chaque occurrence compte, avec ou sans
 * évaluation, sans regrouper les rencontres d'un même couple, et toujours DANS l'événement donné.
 *
 * L'implémentation par défaut répond 0. T-30 DOIT la remplacer (liaison dans AppServiceProvider).
 *
 * Protocole de concurrence : l'appelant d'un retrait, d'une suppression ou d'un changement de date
 * détient un verrou FOR UPDATE sur la ligne de l'événement. T-30 DOIT, dans une seule transaction,
 * prendre un verrou partagé sur cette ligne, relire ENSUITE l'événement (non archivé) et les deux
 * participations (entreprise inscrite ; apprenant non retiré, compte actif), puis créer le passage.
 */
interface EventUsage
{
    public function forEvent(Event $event): int;

    public function forCompany(Event $event, Company $company): int;

    public function forLearner(Event $event, Learner $learner): int;

    /**
     * Passages par entreprise dans cet événement ; une entreprise absente du résultat en a 0.
     *
     * @param  list<int>  $companyIds
     * @return array<int, int>
     */
    public function forCompanies(Event $event, array $companyIds): array;
}
