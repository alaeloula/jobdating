<?php

namespace App\Services\Events;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Action refusée par une règle des événements : réponse 409 avec un code stable, en français. */
class EventRuleException extends RuntimeException
{
    public const PARTICIPANT_HAS_PASSAGES = 'Ce participant a déjà des passages dans cet événement, il ne peut pas être retiré';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function archived(): self
    {
        return new self('event_archived', 'Cet événement est archivé : désarchivez-le pour le modifier.');
    }

    public static function busy(): self
    {
        return new self('event_busy', 'Une autre opération est en cours sur cet événement : réessayez.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], 409);
    }
}
