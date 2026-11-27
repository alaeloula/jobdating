<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\CvController;
use App\Models\CvDocument;
use App\Models\Learner;
use App\Models\Referentials\Competence;
use App\Models\Referentials\ReferentialModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Apprenant pour l'administrateur, la CME et la Direction (ADM-03).
 *
 * CNIE (décision du 19/11/2026 « CNIE visible par l'administrateur, la CME et la Direction », qui
 * remplace RG-APR-06) : complète pour les comptes ayant `learners.view_cnie` ; JAMAIS pour une
 * entreprise (qui n'accède pas à ces routes). Téléphone et identifiant CME : administrateur seulement.
 * Les référentiels liés sont renvoyés même inactifs (contrat T-02).
 *
 * @mixin Learner
 */
class LearnerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Learner $learner */
        $learner = $this->resource;
        $user = $learner->user;
        $viewer = $request->user();
        $admin = $viewer?->currentRole() === Role::Administrateur;
        $class = $learner->schoolClass;

        $data = [
            'id' => $learner->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'campus' => $this->ref($learner->campus),
            'promotion' => $this->ref($learner->promotion),
            'school_class' => $this->ref($class),
            'filiere' => $this->ref($class->filiere),
            'competences' => $learner->competences
                ->sortBy('label')
                ->map(fn (Competence $c) => ['id' => $c->id, 'label' => $c->label, 'active' => $c->active])
                ->values(),
            'status' => match (true) {
                $user->trashed() => 'supprime',
                $user->isDeactivated() => 'desactive',
                default => 'actif',
            },
        ];

        // État du CV (ADM-03, T-20) : présent ou absent, source, date, auteur ; jamais le chemin ni l'empreinte.
        if ($viewer?->can(Permission::CvView->value) === true) {
            /** @var CvDocument|null $cv */
            $cv = $learner->relationLoaded('cv') ? $learner->getRelation('cv') : $learner->cv()->first();
            $data['cv'] = $cv === null ? null : CvController::state($cv);
        }

        if ($viewer?->can(Permission::LearnersViewCnie->value) === true) {
            $data['cnie'] = $learner->cnie;
        }
        if ($admin) {
            $data['phone'] = $user->phone;
            $data['cme_learner_id'] = $learner->cme_learner_id;
            // État dans l'événement courant (T-14), renseigné par la liste.
            if ($learner->getAttribute('event_status') !== null) {
                $data['event_status'] = $learner->getAttribute('event_status');
            }
        }

        return $data;
    }

    /**
     * @return array{id: int, label: string, active: bool}
     */
    private function ref(ReferentialModel $value): array
    {
        return ['id' => $value->id, 'label' => $value->displayLabel(), 'active' => $value->active];
    }
}
