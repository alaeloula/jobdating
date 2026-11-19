<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Recruteur d'une entreprise (évolution 1 V2) : créé par l'entreprise depuis l'évaluation (T-31),
 * consulté par l'administrateur, la CME et la Direction. Jamais visible par un apprenant.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $email
 * @property string $phone
 * @property string|null $job_title
 */
class CompanyRecruiter extends Model
{
    protected $guarded = ['id', 'company_id'];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
