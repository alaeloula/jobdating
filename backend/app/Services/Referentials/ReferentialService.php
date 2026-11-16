<?php

namespace App\Services\Referentials;

use App\Contracts\ClassUsage;
use App\Contracts\TagUsage;
use App\Enums\AuditAction;
use App\Enums\EvaluationTagType;
use App\Enums\ReferentialType;
use App\Models\Referentials\EvaluationTag;
use App\Models\Referentials\Filiere;
use App\Models\Referentials\ReferentialModel;
use App\Models\Referentials\SchoolClass;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Écritures des référentiels (ADM-09) : chaque mutation et sa ligne de journal d'audit sont
 * enregistrées dans la même transaction. Aucune suppression (décision du 16/11/2026).
 */
class ReferentialService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  données validées
     */
    public function create(ReferentialType $type, array $data): ReferentialModel
    {
        return DB::transaction(function () use ($type, $data): ReferentialModel {
            $model = new ($type->model());
            $model->fill($this->only($type, $data));
            $model->active = true;

            if ($type->orderable()) {
                $model->setAttribute('position', $this->nextPosition($type, $model));
            }

            $model->save();

            $this->audit->record(AuditAction::ReferentialCreated, $model, new: $this->snapshot($type, $model));

            return $model;
        });
    }

    /**
     * Modification et changement d'état. La ligne est verrouillée (FOR UPDATE) : les contrôles
     * d'utilisation (classe, étiquette) sont refaits sous ce verrou (protocole décrit dans
     * ClassUsage et TagUsage).
     *
     * @param  array<string, mixed>  $data  données validées
     *
     * @throws ClassInUseException si la filière d'une classe utilisée change sans confirmation
     * @throws ValidationException
     */
    public function update(ReferentialType $type, int $id, array $data, bool $confirmFiliereChange = false): ReferentialModel
    {
        return DB::transaction(function () use ($type, $id, $data, $confirmFiliereChange): ReferentialModel {
            /** @var ReferentialModel $model */
            $model = $type->model()::query()->lockForUpdate()->findOrFail($id);
            $fields = $this->only($type, $data);

            if ($model instanceof Filiere && array_key_exists('code', $fields) && $fields['code'] !== $model->code) {
                throw ValidationException::withMessages(['code' => 'Le code d’une filière est définitif : seul le libellé peut être corrigé.']);
            }

            if ($model instanceof SchoolClass && array_key_exists('filiere_id', $fields) && (int) $fields['filiere_id'] !== $model->filiere_id) {
                // Point d'extension résolu à chaque contrôle (implémentation branchée par T-12).
                $count = app(ClassUsage::class)->learnersCount($model);
                if ($count > 0 && ! $confirmFiliereChange) {
                    throw new ClassInUseException($count);
                }
            }

            if ($model instanceof EvaluationTag && array_key_exists('type', $fields) && $fields['type'] !== $model->type->value) {
                if (app(TagUsage::class)->isUsed($model)) {
                    throw ValidationException::withMessages(['type' => 'Cette étiquette est déjà utilisée dans des évaluations : son type ne peut plus changer. Créez une nouvelle étiquette et désactivez celle-ci.']);
                }
            }

            $before = $this->snapshot($type, $model);
            $wasActive = $model->active;

            $model->fill($fields);
            if (array_key_exists('active', $data)) {
                $model->active = (bool) $data['active'];
            }
            if ($model instanceof EvaluationTag && $model->isDirty('type')) {
                $model->setAttribute('position', $this->nextPosition($type, $model));
            }

            if (! $model->isDirty()) {
                return $model;
            }

            $model->save();
            $after = $this->snapshot($type, $model);

            $old = array_diff_key(array_diff_assoc($before, $after), ['active' => true]);
            $new = array_intersect_key($after, $old);
            if ($old !== []) {
                $this->audit->record(AuditAction::ReferentialUpdated, $model, $old, $new);
            }
            if ($wasActive !== $model->active) {
                $this->audit->record(
                    $model->active ? AuditAction::ReferentialReactivated : AuditAction::ReferentialDeactivated,
                    $model,
                    ['active' => $wasActive],
                    ['active' => $model->active],
                );
            }

            return $model;
        });
    }

    /**
     * Nouvel ordre d'un groupe : toutes les valeurs du groupe, chacune une fois.
     *
     * @param  list<int>  $ids
     *
     * @throws ValidationException
     */
    public function reorder(ReferentialType $type, array $ids, ?EvaluationTagType $tagType = null): void
    {
        DB::transaction(function () use ($type, $ids, $tagType): void {
            $rows = $this->group($type, $tagType)->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $current = $rows->pluck('id')->all();

            if (count($ids) !== count(array_unique($ids)) || array_diff($ids, $current) !== [] || count($ids) !== count($current)) {
                throw ValidationException::withMessages(['ids' => 'L’ordre doit contenir chaque valeur de la liste une seule fois.']);
            }

            $byId = $rows->keyBy('id');
            $before = $rows->map(fn (ReferentialModel $m) => $m->displayLabel())->all();
            $after = [];
            // Positions 1, 2, 3… comptées ici, jamais tirées des clés reçues (défense en profondeur).
            $position = 0;
            foreach ($ids as $id) {
                $position++;
                /** @var ReferentialModel $row */
                $row = $byId[$id];
                $after[] = $row->displayLabel();
                if ((int) $row->getAttribute('position') !== $position) {
                    $row->setAttribute('position', $position);
                    $row->save();
                }
            }

            if ($before !== $after) {
                $list = $type->label().($tagType !== null ? ' – '.$tagType->label() : '');
                $this->audit->record(AuditAction::ReferentialReordered, old: ['liste' => $list, 'ordre' => $before], new: ['liste' => $list, 'ordre' => $after]);
            }
        });
    }

    /**
     * Valeurs d'un groupe ordonné (opportunités, ou étiquettes d'un type).
     *
     * @return Builder<ReferentialModel>
     */
    public function group(ReferentialType $type, ?EvaluationTagType $tagType = null): Builder
    {
        $query = $type->model()::query();

        return $tagType !== null ? $query->where('type', $tagType->value) : $query;
    }

    private function nextPosition(ReferentialType $type, ReferentialModel $model): int
    {
        $tagType = $model instanceof EvaluationTag ? $model->type : null;

        return (int) $this->group($type, $tagType)->max('position') + 1;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function only(ReferentialType $type, array $data): array
    {
        return array_intersect_key($data, array_flip($type->editableFields()));
    }

    /**
     * Valeurs journalisées : champs modifiables et état.
     *
     * @return array<string, mixed>
     */
    private function snapshot(ReferentialType $type, ReferentialModel $model): array
    {
        $values = [];
        foreach ($type->editableFields() as $field) {
            $value = $model->getAttribute($field);
            $values[$field] = $value instanceof \BackedEnum ? $value->value : $value;
        }
        $values['active'] = $model->active;

        return $values;
    }
}
