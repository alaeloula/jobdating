<?php

namespace App\Services\Accounts;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Action refusée par une règle de gestion des comptes (RG-USR-03, protections du 18/11/2026) :
 * réponse 422 avec un code stable et un message en français.
 */
class AccountRuleException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function self(string $action): self
    {
        return new self('own_account', "Vous ne pouvez pas {$action} votre propre compte.");
    }

    public static function lastAdmin(): self
    {
        return new self('last_admin', 'Il doit toujours rester au moins un administrateur actif.');
    }

    public static function deleted(): self
    {
        return new self('account_deleted', 'Ce compte est supprimé : restaurez-le d’abord.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], 422);
    }
}
