<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\CurrentUserResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Utilisateur connecté : identité, rôle, permissions, page d'accueil du rôle.
 */
class MeController extends Controller
{
    public function __invoke(Request $request): CurrentUserResource
    {
        /** @var User $user */
        $user = $request->user();

        return CurrentUserResource::make($user);
    }
}
