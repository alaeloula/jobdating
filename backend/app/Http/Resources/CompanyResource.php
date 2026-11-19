<?php

namespace App\Http\Resources;

use App\Models\Company;
use App\Models\CompanyRecruiter;
use App\Models\Referentials\Competence;
use App\Models\Referentials\Filiere;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche entreprise pour l'administrateur et la CME (ADM-02). JAMAIS de mot de passe : les
 * identifiants ne sont renvoyés qu'à part, une seule fois, à la création ou à la réinitialisation.
 *
 * @mixin Company
 */
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Company $company */
        $company = $this->resource;
        $user = $company->user;

        return [
            'id' => $company->id,
            'name' => $company->name,
            'sector' => $company->sector !== null
                ? ['id' => $company->sector->id, 'label' => $company->sector->label, 'active' => $company->sector->active]
                : null,
            'description' => $company->description,
            'website' => $company->website,
            'contact_name' => $company->contact_name,
            'contact_email' => $company->contact_email,
            'has_logo' => $company->logo_path !== null,
            'username' => $user?->username,
            'status' => $user?->isDeactivated() ? 'desactive' : 'actif',
            'credentials_version' => $company->credentials_version,
            'credentials_printed' => $company->credentials_printed_at !== null,
            'filieres' => $company->filieres->map(fn (Filiere $f) => ['id' => $f->id, 'label' => $f->displayLabel(), 'active' => $f->active])->values(),
            'competences' => $company->competences->map(fn (Competence $c) => ['id' => $c->id, 'label' => $c->label, 'active' => $c->active])->values(),
            'recruiters' => $company->recruiters->map(fn (CompanyRecruiter $r) => [
                'id' => $r->id, 'name' => $r->name, 'email' => $r->email, 'phone' => $r->phone, 'job_title' => $r->job_title,
            ])->values(),
        ];
    }
}
