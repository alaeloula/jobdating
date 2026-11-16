<?php

namespace App\Http\Controllers;

use App\Enums\EvaluationTagType;
use App\Enums\ReferentialType;
use App\Models\Referentials\ReferentialModel;
use App\Services\Referentials\ReferentialService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Référentiels (ADM-09). Gestion réservée à referentials.manage (administrateur, CME) ;
 * lecture des options ouverte à tout utilisateur connecté. Aucune route de suppression.
 */
class ReferentialController extends Controller
{
    public function __construct(private readonly ReferentialService $service) {}

    /** Toutes les valeurs d'un référentiel (gestion). */
    public function index(ReferentialType $type): JsonResponse
    {
        $values = $this->sorted($type)->get()->map(fn (ReferentialModel $m) => $this->present($type, $m));

        return response()->json(['data' => $values]);
    }

    /**
     * Options : valeurs actives (saisie), ou toutes avec leur état (`inactives=1`, filtres
     * historiques). Ni e-mail de campus, ni clé technique.
     */
    public function options(Request $request, ReferentialType $type): JsonResponse
    {
        $validated = $request->validate(['inactives' => ['sometimes', 'boolean']]);
        $withInactive = (bool) ($validated['inactives'] ?? false);

        $values = $this->sorted($type)
            ->when(! $withInactive, fn (Builder $q) => $q->where('active', true))
            ->get()
            ->map(fn (ReferentialModel $m) => $type->option($m));

        return response()->json(['data' => $values]);
    }

    public function store(Request $request, ReferentialType $type): JsonResponse
    {
        $data = $request->validate($type->rules(null, $request->all()), $this->messages());

        $model = $this->service->create($type, $data);

        return response()->json(['data' => $this->present($type, $model)], 201);
    }

    public function update(Request $request, ReferentialType $type, int $id): JsonResponse
    {
        $current = $type->model()::query()->findOrFail($id);

        $data = $request->validate(
            $type->rules($current, $request->all()) + ['active' => ['sometimes', 'boolean']],
            $this->messages(),
        );

        // Confirmation du changement de filière d'une classe : seule la valeur booléenne true compte.
        $confirm = $request->json('confirmer_changement_filiere');
        if ($request->exists('confirmer_changement_filiere') && ! is_bool($confirm)) {
            throw ValidationException::withMessages([
                'confirmer_changement_filiere' => 'La confirmation doit valoir true ou false.',
            ]);
        }

        $model = $this->service->update($type, $id, $data, $confirm === true);

        return response()->json(['data' => $this->present($type, $model)]);
    }

    /** Nouvel ordre (opportunités ; étiquettes d'un type). */
    public function order(Request $request, ReferentialType $type): Response
    {
        abort_unless($type->orderable(), 404);

        $validated = $request->validate([
            // Une vraie liste JSON : un objet est refusé (revue Codex T-02, constat 2).
            'ids' => ['required', 'list', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
            'type' => [$type === ReferentialType::Etiquettes ? 'required' : 'prohibited', Rule::enum(EvaluationTagType::class)],
        ], [
            'ids.*' => 'L’ordre doit contenir chaque valeur de la liste une seule fois.',
            'type.required' => 'Précisez le type d’étiquette.',
        ]);

        $tagType = isset($validated['type']) ? EvaluationTagType::from($validated['type']) : null;
        $this->service->reorder($type, array_map('intval', $validated['ids']), $tagType);

        return response()->noContent();
    }

    /**
     * @return Builder<ReferentialModel>
     */
    private function sorted(ReferentialType $type): Builder
    {
        $query = $type->model()::query();

        return match ($type) {
            ReferentialType::Filieres => $query->orderBy('code'),
            ReferentialType::Classes, ReferentialType::Campus => $query->orderBy('name'),
            ReferentialType::Opportunites => $query->orderBy('position')->orderBy('id'),
            ReferentialType::Etiquettes => $query->orderBy('type')->orderBy('position')->orderBy('id'),
            default => $query->orderBy('label'),
        };
    }

    /**
     * Représentation de gestion : champs modifiables, état, ordre (sans clé technique).
     *
     * @return array<string, mixed>
     */
    private function present(ReferentialType $type, ReferentialModel $model): array
    {
        $values = ['id' => $model->getKey(), 'display_label' => $model->displayLabel(), 'active' => $model->active];
        foreach ($type->editableFields() as $field) {
            $value = $model->getAttribute($field);
            $values[$field] = $value instanceof \BackedEnum ? $value->value : $value;
        }
        if ($type->orderable()) {
            $values['position'] = (int) $model->getAttribute('position');
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'required' => 'Ce champ est obligatoire.',
            'unique' => 'Cette valeur existe déjà dans la liste.',
            'max' => 'Ce champ est trop long.',
            'code.regex' => 'Le code ne peut contenir que des lettres, chiffres, points, tirets et soulignés.',
            'filiere_id.exists' => 'Filière inconnue.',
            'contact_email.email' => 'Adresse e-mail invalide.',
            'type.unique' => 'Une étiquette de ce type porte déjà ce libellé.',
            'type.*' => 'Type d’étiquette inconnu.',
            'active.boolean' => 'L’état doit valoir true ou false.',
        ];
    }
}
