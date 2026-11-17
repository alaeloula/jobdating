<?php

namespace App\Jobs;

use App\Enums\LinkRequest;
use App\Enums\PasswordTokenType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Journal « lien remis à la messagerie » (décision F), séparé de l'envoi : s'il échoue, seule
 * cette tâche est reprise, sans renvoyer l'e-mail. Ne contient aucun jeton.
 */
class RecordLinkSent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly int $userId,
        public readonly PasswordTokenType $type,
        public readonly LinkRequest $request,
        public readonly ?int $adminId,
    ) {}

    public function handle(): void
    {
        LinkAudit::recordSent($this->userId, $this->type, $this->request, $this->adminId);
    }
}
