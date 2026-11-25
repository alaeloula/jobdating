<?php

namespace App\Http\Controllers;

use App\Contracts\EventUsage;
use App\Enums\Permission;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventCompany;
use App\Models\Learner;
use App\Models\User;
use App\Services\Events\EventParticipationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Participants d'un événement (ADM-07, T-14). Ajouts et retraits : administrateur
 * (`participants.manage`). Emplacements : administrateur et CME (`locations.manage`).
 */
class EventParticipantController extends Controller
{
    public function __construct(
        private readonly EventParticipationService $participation,
        private readonly EventUsage $usage,
    ) {}

    public function companies(Request $request, int $id): JsonResponse
    {
        $event = Event::query()->findOrFail($id);
        $filter = $request->validate(['filter' => ['nullable', Rule::in(['sans_emplacement', 'non_imprimee'])]])['filter'] ?? null;
        $manager = $this->actor($request)->can(Permission::ParticipantsManage->value);
        // « Fiche non imprimée » (identifiants) ne concerne que la gestion des comptes.
        abort_if($filter === 'non_imprimee' && ! $manager, 403);

        $rows = EventCompany::query()->with(['company.sector', 'company.user'])->where('event_id', $event->id)->get()
            ->sortBy(fn (EventCompany $row) => mb_strtolower($row->company->name))->values();
        $missing = fn (EventCompany $row) => $row->floor === null && $row->table_label === null;
        $unprinted = fn (EventCompany $row) => $row->company->credentials_printed_at === null;

        $selected = match ($filter) {
            'sans_emplacement' => $rows->filter($missing),
            'non_imprimee' => $rows->filter($unprinted),
            default => $rows,
        };
        $passages = $this->usage->forCompanies($event, $selected->pluck('company_id')->map(fn ($v) => (int) $v)->all());

        return response()->json([
            'data' => $selected->values()->map(function (EventCompany $row) use ($passages, $manager) {
                $company = $row->company;
                $count = $passages[$company->id] ?? 0;
                $item = [
                    'id' => $company->id,
                    'name' => $company->name,
                    'sector' => $company->sector?->label,
                    'status' => $company->user?->isDeactivated() ? 'desactive' : 'actif',
                    'floor' => $row->floor,
                    'table_label' => $row->table_label,
                    'passages' => $count,
                ];
                if ($manager) {
                    $item['username'] = $company->user?->username;
                    $item['credentials_printed'] = $company->credentials_printed_at !== null;
                    $item['can_remove'] = $count === 0;
                }

                return $item;
            }),
            'meta' => [
                'total' => $rows->count(),
                'without_location' => $rows->filter($missing)->count(),
                'not_printed' => $manager ? $rows->filter($unprinted)->count() : null,
            ],
        ]);
    }

    /** Entreprises actives pas encore inscrites (éditions précédentes comprises, RG-PAR-07). */
    public function availableCompanies(int $id): JsonResponse
    {
        $event = Event::query()->findOrFail($id);
        $companies = Company::query()->with('sector')
            ->whereHas('user', fn (Builder $u) => $u->whereNull('deactivated_at')->whereNull('deleted_at'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('event_companies')
                ->whereColumn('event_companies.company_id', 'companies.id')
                ->where('event_companies.event_id', $event->id))
            ->orderBy('name')->orderBy('id')->get()
            ->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name, 'sector' => $c->sector?->label])
            ->values();

        return response()->json(['data' => $companies]);
    }

    public function addCompanies(Request $request, int $id): JsonResponse
    {
        $event = Event::query()->findOrFail($id);
        $data = $request->validate([
            'company_ids' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'company_ids.*' => ['integer', 'distinct', Rule::exists('companies', 'id')],
        ], [
            'company_ids.required' => 'Choisissez au moins une entreprise.',
            'company_ids.min' => 'Choisissez au moins une entreprise.',
            'company_ids.max' => 'Trop d’entreprises sélectionnées.',
            'company_ids.*.exists' => 'Une entreprise sélectionnée est introuvable.',
            'company_ids.*.distinct' => 'Une entreprise est en double.',
        ]);

        return response()->json(['report' => $this->participation->addCompanies(
            $event,
            array_map('intval', $data['company_ids']),
            $this->actor($request),
        )]);
    }

    public function removeCompany(Request $request, int $id, int $company): JsonResponse
    {
        $this->participation->removeCompany(Event::query()->findOrFail($id), Company::query()->findOrFail($company), $this->actor($request));

        return response()->json(null, 204);
    }

    public function updateLocation(Request $request, int $id, int $company): JsonResponse
    {
        $event = Event::query()->findOrFail($id);
        $target = Company::query()->findOrFail($company);
        $data = $request->validate([
            'floor' => ['present', 'nullable', 'string', 'max:50'],
            'table_label' => ['present', 'nullable', 'string', 'max:50'],
        ], [
            'present' => 'Ce champ est attendu (vide si aucun).',
            'max' => '50 caractères au plus.',
        ]);

        $row = $this->participation->updateLocation($event, $target, $data['floor'], $data['table_label'], $this->actor($request));

        return response()->json(['data' => ['id' => $target->id, 'floor' => $row->floor, 'table_label' => $row->table_label]]);
    }

    public function removeLearner(Request $request, int $id, int $learner): JsonResponse
    {
        $this->participation->removeLearner(Event::query()->findOrFail($id), Learner::query()->findOrFail($learner), $this->actor($request));

        return response()->json(null, 204);
    }

    public function reinstateLearner(Request $request, int $id, int $learner): JsonResponse
    {
        $this->participation->reinstateLearner(Event::query()->findOrFail($id), Learner::query()->findOrFail($learner), $this->actor($request));

        return response()->json(null, 204);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
