<?php

namespace App\Services\Events;

use App\Contracts\EventUsage;
use App\Models\Company;
use App\Models\Event;
use App\Models\Learner;

/** Tant que les passages n'existent pas : aucun passage. À remplacer par T-30. */
class NoEventUsage implements EventUsage
{
    public function forEvent(Event $event): int
    {
        return 0;
    }

    public function forCompany(Event $event, Company $company): int
    {
        return 0;
    }

    public function forLearner(Event $event, Learner $learner): int
    {
        return 0;
    }

    public function forCompanies(Event $event, array $companyIds): array
    {
        return [];
    }
}
