<?php

namespace App\Services\Learners;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Learner;
use App\Models\Referentials\Campus;
use App\Models\Referentials\Competence;
use App\Models\Referentials\Filiere;
use App\Models\Referentials\Promotion;
use App\Models\Referentials\SchoolClass;
use App\Models\User;
use App\Support\Cnie;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtres de la liste des apprenants (plan T-12, §5) : UNE seule logique pour la liste, le
 * comptage d'une sélection et les actions en masse.
 *
 * Visibilité : les comptes supprimés ne sont visibles que de l'administrateur, et seulement avec
 * le filtre « Supprimés ». La recherche par CNIE (exacte, normalisée) est ouverte aux comptes qui
 * voient la CNIE (`learners.view_cnie` : administrateur, CME, Direction ; décision du 19/11).
 */
final class LearnerFilters
{
    public const STATUSES = ['actif', 'desactive', 'supprime'];

    /**
     * @param  array{search?: string|null, status?: string|null, campus_id?: int|null, promotion_id?: int|null, class_id?: int|null, filiere_id?: int|null, competence_id?: int|null}  $filters
     */
    public function __construct(public readonly array $filters, private readonly User $viewer) {}

    public function isAdmin(): bool
    {
        return $this->viewer->currentRole() === Role::Administrateur;
    }

    /**
     * Tous les apprenants visibles de l'acteur, sans filtre : supprimés compris pour
     * l'administrateur seulement.
     *
     * @return Builder<Learner>
     */
    public function visible(): Builder
    {
        $query = Learner::query()
            ->select('learners.*')
            ->join('users', 'users.id', '=', 'learners.user_id');

        return $this->isAdmin() ? $query : $query->whereNull('users.deleted_at');
    }

    /**
     * Apprenants visibles, filtrés. Jointure sur `users` (tri et statut) : les colonnes du compte
     * sont lues directement, la suppression logique est donc traitée explicitement.
     *
     * @return Builder<Learner>
     */
    public function query(): Builder
    {
        $query = Learner::query()
            ->select('learners.*')
            ->join('users', 'users.id', '=', 'learners.user_id');

        $status = $this->filters['status'] ?? null;
        if ($status === 'supprime' && $this->isAdmin()) {
            $query->whereNotNull('users.deleted_at');
        } else {
            $query->whereNull('users.deleted_at');
            match ($status) {
                'actif' => $query->whereNull('users.deactivated_at'),
                'desactive' => $query->whereNotNull('users.deactivated_at'),
                default => null,
            };
        }

        $search = trim((string) ($this->filters['search'] ?? ''));
        if ($search !== '') {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $cnie = Cnie::normalize($search);
            $query->where(function (Builder $q) use ($term, $cnie): void {
                $q->where('users.first_name', 'like', $term)
                    ->orWhere('users.last_name', 'like', $term)
                    ->orWhere('users.email', 'like', $term)
                    ->orWhereRaw("CONCAT(users.first_name, ' ', users.last_name) like ?", [$term]);
                if ($this->viewer->can(Permission::LearnersViewCnie->value) && Cnie::isValid($cnie)) {
                    $q->orWhere('learners.cnie', $cnie);
                }
            });
        }

        foreach (['campus_id', 'promotion_id', 'class_id'] as $field) {
            if (isset($this->filters[$field])) {
                $query->where("learners.{$field}", (int) $this->filters[$field]);
            }
        }
        if (isset($this->filters['filiere_id'])) {
            $query->whereIn('learners.class_id', SchoolClass::query()->select('id')->where('filiere_id', (int) $this->filters['filiere_id']));
        }
        if (isset($this->filters['competence_id'])) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('learner_competence')
                ->whereColumn('learner_competence.learner_id', 'learners.id')
                ->where('learner_competence.competence_id', (int) $this->filters['competence_id']));
        }

        return $query;
    }

    /**
     * Description des filtres pour la synthèse d'une action en masse (plan T-12 v2, constat 2) :
     * libellés seulement, JAMAIS la recherche libre (elle peut contenir une CNIE).
     *
     * @return array<string, string>
     */
    public function describe(): array
    {
        $labels = [];
        $status = $this->filters['status'] ?? null;
        if ($status !== null) {
            $labels['statut'] = match ($status) {
                'actif' => 'Actifs',
                'desactive' => 'Désactivés',
                default => 'Supprimés',
            };
        }
        $models = [
            'campus' => [Campus::class, 'campus_id'],
            'promotion' => [Promotion::class, 'promotion_id'],
            'classe' => [SchoolClass::class, 'class_id'],
            'filiere' => [Filiere::class, 'filiere_id'],
            'competence' => [Competence::class, 'competence_id'],
        ];
        foreach ($models as $label => [$model, $field]) {
            if (isset($this->filters[$field])) {
                $value = $model::query()->find((int) $this->filters[$field]);
                $labels[$label] = $value?->displayLabel() ?? '#'.$this->filters[$field];
            }
        }
        if (trim((string) ($this->filters['search'] ?? '')) !== '') {
            $labels['recherche'] = 'oui';
        }

        return $labels;
    }
}
