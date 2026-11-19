<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Resources\LearnerResource;
use App\Models\Learner;
use App\Models\User;
use App\Services\Learners\LearnerFilters;
use App\Services\Learners\LearnerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Apprenants (ADM-03, T-12). Routes limitées aux RÔLES administrateur, CME et Direction (l'entreprise
 * a `learners.view` pour T-23 : elle n'accède jamais à ces routes). Écritures : administrateur ;
 * compétences : administrateur et CME.
 *
 * La recherche passe par POST (corps JSON) : une CNIE recherchée n'apparaît jamais dans une adresse
 * ni dans les journaux d'accès du serveur web (plan T-12 v2, constat 2).
 */
class LearnerController extends Controller
{
    private const PER_PAGE = 25;

    private const RELATIONS = ['user', 'campus', 'promotion', 'schoolClass.filiere', 'competences'];

    public function __construct(private readonly LearnerService $learners) {}

    public function search(Request $request): AnonymousResourceCollection
    {
        $filters = $this->filters($request, $request->validate($this->filterRules('') + [
            'page' => ['nullable', 'integer', 'min:1'],
        ], $this->messages()));

        $page = $filters->query()->with(self::RELATIONS)
            ->orderBy('users.last_name')->orderBy('users.first_name')->orderBy('learners.id')
            ->paginate(self::PER_PAGE, page: (int) $request->input('page', 1));

        return LearnerResource::collection($page);
    }

    public function show(Request $request, int $id): LearnerResource
    {
        return LearnerResource::make($this->find($request, $id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(null), $this->messages());
        $created = $this->learners->create($data, $this->actor($request));

        return $this->withPassword($created['learner'], $created['password'], 201);
    }

    public function update(Request $request, int $id): LearnerResource
    {
        $learner = $this->find($request, $id);
        $data = $request->validate($this->rules($learner), $this->messages());

        return LearnerResource::make($this->fresh($this->learners->update($learner, $data, $this->actor($request))));
    }

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $learner = $this->find($request, $id);
        $password = $this->learners->resetPassword($learner, $this->actor($request));

        return $this->withPassword($learner, $password, 200);
    }

    public function deactivate(Request $request, int $id): LearnerResource
    {
        return LearnerResource::make($this->fresh($this->learners->deactivate($this->find($request, $id))));
    }

    public function reactivate(Request $request, int $id): LearnerResource
    {
        return LearnerResource::make($this->fresh($this->learners->reactivate($this->find($request, $id))));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->learners->delete($this->find($request, $id));

        return response()->json(null, 204);
    }

    public function restore(Request $request, int $id): LearnerResource
    {
        return LearnerResource::make($this->fresh($this->learners->restore($this->find($request, $id))));
    }

    public function competences(Request $request, int $id): LearnerResource
    {
        $learner = $this->find($request, $id);
        $data = $request->validate([
            'competence_ids' => ['present', 'list', 'max:200'],
            'competence_ids.*' => ['integer', 'distinct', Rule::exists('competences', 'id')],
        ], [
            'competence_ids.present' => 'La liste est obligatoire (vide si aucune compétence).',
            'competence_ids.list' => 'La liste est invalide.',
            'competence_ids.*.distinct' => 'Une compétence est en double.',
            'competence_ids.*.exists' => 'Compétence inconnue.',
            'competence_ids.*.integer' => 'Valeur invalide.',
        ]);

        return LearnerResource::make($this->fresh($this->learners->updateCompetences(
            $learner,
            array_map('intval', $data['competence_ids']),
            $this->actor($request),
        )));
    }

    // ------------------------------------------------------------------ Actions en masse

    /** « N sélectionnés, dont M concernés » (plan T-12 v2, §5). */
    public function selectionCount(Request $request): JsonResponse
    {
        $data = $request->validate($this->selectionRules() + [
            'action' => ['required', Rule::in([LearnerService::ACTION_DEACTIVATE, LearnerService::ACTION_ADD_COMPETENCE])],
            'competence_id' => ['required_if:action,'.LearnerService::ACTION_ADD_COMPETENCE, 'nullable', 'integer', Rule::exists('competences', 'id')],
        ], $this->messages());

        // Droit de l'action demandée : la désactivation est réservée à l'administrateur.
        abort_if($data['action'] === LearnerService::ACTION_DEACTIVATE && ! $this->isAdmin($request), 403);

        [$selection] = $this->selection($request, $data);
        $competenceId = isset($data['competence_id']) ? (int) $data['competence_id'] : null;

        return response()->json(['data' => [
            'selected' => (clone $selection)->count(),
            'eligible' => $this->learners->eligible($selection, $data['action'], $competenceId)->count(),
        ]]);
    }

    /** Action déterminée par la ROUTE, jamais par le corps de la requête (contre-revue T-12). */
    public function bulkDeactivate(Request $request): JsonResponse
    {
        $data = $request->validate($this->selectionRules() + $this->expectedRule(), $this->messages());
        [$selection, $filters] = $this->selection($request, $data);

        $changed = $this->learners->bulkDeactivate($selection, (int) $data['expected_eligible'], $this->describe($data, $filters), $this->actor($request));

        return response()->json(['data' => ['changed' => $changed]]);
    }

    public function bulkCompetences(Request $request): JsonResponse
    {
        $data = $request->validate($this->selectionRules() + $this->expectedRule() + [
            'competence_id' => ['required', 'integer', Rule::exists('competences', 'id')],
        ], $this->messages());
        [$selection, $filters] = $this->selection($request, $data);

        $changed = $this->learners->bulkAddCompetence($selection, (int) $data['expected_eligible'], (int) $data['competence_id'], $this->describe($data, $filters), $this->actor($request));

        return response()->json(['data' => ['changed' => $changed]]);
    }

    // ------------------------------------------------------------------ Outils

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Builder<Learner>, 1: LearnerFilters}
     */
    private function selection(Request $request, array $data): array
    {
        // Sélection EXPLICITE obligatoire (revue Codex T-12, constat 1) : une requête sans `ids` ni
        // `filters` est refusée ; `filters: {}` reste la demande explicite de tous les apprenants visibles.
        if (! $request->exists('ids') && ! $request->exists('filters')) {
            throw ValidationException::withMessages(['ids' => 'Choisissez des apprenants ou un filtre.']);
        }

        $filters = $this->filters($request, is_array($data['filters'] ?? null) ? $data['filters'] : []);
        $ids = isset($data['ids']) ? array_map('intval', $data['ids']) : null;

        return [$this->learners->selection($ids, $filters), $filters];
    }

    /**
     * Description de la sélection pour la synthèse du journal (sans recherche libre).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function describe(array $data, LearnerFilters $filters): array
    {
        if (isset($data['ids'])) {
            return ['mode' => 'apprenants cochés'];
        }

        return ['mode' => 'filtre'] + $filters->describe();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function filters(Request $request, array $values): LearnerFilters
    {
        $clean = [];
        foreach (['search', 'status'] as $key) {
            if (isset($values[$key]) && $values[$key] !== '') {
                $clean[$key] = (string) $values[$key];
            }
        }
        foreach (['campus_id', 'promotion_id', 'class_id', 'filiere_id', 'competence_id'] as $key) {
            if (isset($values[$key])) {
                $clean[$key] = (int) $values[$key];
            }
        }

        /** @var array{search?: string, status?: string, campus_id?: int, promotion_id?: int, class_id?: int, filiere_id?: int, competence_id?: int} $clean */
        return new LearnerFilters($clean, $this->actor($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function filterRules(string $prefix): array
    {
        $statuses = request()->user()?->currentRole() === Role::Administrateur ? LearnerFilters::STATUSES : ['actif', 'desactive'];

        return [
            $prefix.'search' => ['nullable', 'string', 'max:100'],
            $prefix.'status' => ['nullable', Rule::in($statuses)],
            $prefix.'campus_id' => ['nullable', 'integer'],
            $prefix.'promotion_id' => ['nullable', 'integer'],
            $prefix.'class_id' => ['nullable', 'integer'],
            $prefix.'filiere_id' => ['nullable', 'integer'],
            $prefix.'competence_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * Sélection : identifiants OU filtres, jamais les deux.
     *
     * @return array<string, mixed>
     */
    private function selectionRules(): array
    {
        $request = request();
        // Présence testée par la CLÉ (un filtre vide `{}` vaut « tous les apprenants visibles »).
        $exclusive = function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
            if ($request->exists('ids') && $request->exists('filters')) {
                $fail('Choisissez des apprenants OU un filtre, pas les deux.');
            }
        };

        return [
            'ids' => ['sometimes', 'array', 'list', 'min:1', 'max:'.LearnerService::BULK_LIMIT, $exclusive],
            'ids.*' => ['integer', 'distinct'],
            'filters' => ['sometimes', 'present', 'array', $exclusive],
        ] + $this->filterRules('filters.');
    }

    /** @return array<string, mixed> */
    private function expectedRule(): array
    {
        return ['expected_eligible' => ['required', 'integer', 'min:0', 'max:'.LearnerService::BULK_LIMIT]];
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Learner $learner): array
    {
        $required = $learner === null ? 'required' : 'sometimes';

        return [
            // Format et unicité vérifiés par le service après normalisation (App\Support\Cnie).
            'cnie' => [$required, 'string', 'max:60'],
            'first_name' => [$required, 'string', 'max:100'],
            'last_name' => [$required, 'string', 'max:100'],
            'email' => [$required, 'string', 'max:255', 'email:rfc', Rule::unique('users', 'email')->ignore($learner?->user_id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'campus_id' => [$required, 'integer', Rule::exists('campuses', 'id')],
            'promotion_id' => [$required, 'integer', Rule::exists('promotions', 'id')],
            'class_id' => [$required, 'integer', Rule::exists('classes', 'id')],
            'cme_learner_id' => ['nullable', 'string', 'max:100', Rule::unique('learners', 'cme_learner_id')->ignore($learner?->id)],
        ];
    }

    /**
     * Messages sans jamais répéter la valeur saisie (une CNIE ne figure dans aucune erreur).
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'required' => 'Ce champ est obligatoire.',
            'max' => 'Ce champ est trop long.',
            'email.email' => 'Adresse e-mail invalide.',
            'email.unique' => 'Cet e-mail est déjà utilisé par un autre compte.',
            'cme_learner_id.unique' => 'Cet identifiant CME est déjà utilisé.',
            'campus_id.exists' => 'Campus inconnu.',
            'promotion_id.exists' => 'Promotion inconnue.',
            'class_id.exists' => 'Classe inconnue.',
            'integer' => 'Valeur invalide.',
            'in' => 'Valeur invalide.',
            'ids.min' => 'Aucun apprenant sélectionné.',
            'ids.max' => 'Trop d’apprenants sélectionnés.',
            'ids.*.distinct' => 'Un apprenant est en double.',
            'competence_id.required' => 'Choisissez une compétence.',
            'competence_id.required_if' => 'Choisissez une compétence.',
            'competence_id.exists' => 'Compétence inconnue.',
        ];
    }

    /** Fiche visible de l'acteur : un apprenant supprimé n'existe que pour l'administrateur. */
    private function find(Request $request, int $id): Learner
    {
        $learner = Learner::query()->with(self::RELATIONS)->findOrFail($id);
        abort_if($learner->user->trashed() && ! $this->isAdmin($request), 404);

        return $learner;
    }

    private function fresh(Learner $learner): Learner
    {
        return Learner::query()->with(self::RELATIONS)->findOrFail($learner->getKey());
    }

    /** Mot de passe affiché UNE seule fois (création, réinitialisation) ; jamais en cache. */
    private function withPassword(Learner $learner, string $password, int $status): JsonResponse
    {
        return response()->json([
            'data' => LearnerResource::make($this->fresh($learner))->resolve(),
            'password' => $password,
        ], $status)->header('Cache-Control', 'no-store, private');
    }

    private function isAdmin(Request $request): bool
    {
        return $this->actor($request)->currentRole() === Role::Administrateur;
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
