<?php

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\LinkRequest;
use App\Enums\PasswordTokenType;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Journal des envois de liens (T-05, décision F) : auteur explicite, jamais déduit d'une
 * session ; jamais de jeton ni de message d'erreur brut.
 */
final class LinkAudit
{
    public static function recordSent(int $userId, PasswordTokenType $type, LinkRequest $request, ?int $adminId): void
    {
        self::record($type->sentAction(), $userId, $type, $request, $adminId);
    }

    public static function recordFailure(int $userId, PasswordTokenType $type, LinkRequest $request, ?int $adminId): void
    {
        self::record(AuditAction::LinkEmailFailed, $userId, $type, $request, $adminId);
    }

    private static function record(AuditAction $action, int $userId, PasswordTokenType $type, LinkRequest $request, ?int $adminId): void
    {
        $user = User::withTrashed()->find($userId);
        $admin = $adminId !== null ? User::withTrashed()->find($adminId) : null;

        app(AuditLogger::class)->record(
            $action,
            $user,
            new: ['lien' => $type->label(), 'origine' => $request->originLabel()],
            actor: $admin,
            visitor: $request->isPublic(),
        );
    }
}
