<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Déconnexion : la session est fermée immédiatement (RG-AUTH-04).
 */
class LogoutController extends Controller
{
    public function __invoke(Request $request, AuditLogger $audit): Response
    {
        $user = $request->user();
        if ($user instanceof User) {
            $audit->record(AuditAction::Logout, $user, actor: $user);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
