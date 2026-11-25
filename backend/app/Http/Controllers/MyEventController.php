<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Learner;
use App\Models\User;
use App\Services\Events\EventParticipationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Événement du compte connecté (ENT-01, APP-00, décisions 10 et 12 du 23/11) : toujours l'événement
 * COURANT et toujours le propriétaire du compte. Aucun identifiant reçu n'est utilisé (RG-ACC-02).
 */
class MyEventController extends Controller
{
    public function __construct(private readonly EventParticipationService $participation) {}

    public function company(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        abort_unless($user->currentRole() === Role::Entreprise, 403);
        $company = Company::query()->where('user_id', $user->getKey())->firstOrFail();

        return response()->json(['data' => $this->participation->companyView($company)]);
    }

    public function learner(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        abort_unless($user->currentRole() === Role::Apprenant, 403);
        $learner = Learner::query()->where('user_id', $user->getKey())->first();

        return response()->json(['data' => $learner === null ? null : $this->participation->learnerView($learner)]);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
