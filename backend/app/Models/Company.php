<?php

namespace App\Models;

use App\Contracts\HasAuditLabel;
use App\Models\Referentials\Competence;
use App\Models\Referentials\Filiere;
use App\Models\Referentials\Sector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Fiche entreprise (ADM-02). Le compte de connexion est `user` (rôle entreprise, PC-05).
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property int|null $sector_id
 * @property string|null $description
 * @property string|null $website
 * @property string|null $logo_path
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property int $credentials_version
 * @property Carbon|null $credentials_printed_at
 */
class Company extends Model implements HasAuditLabel
{
    /** Champs modifiables par l'administrateur (jamais : compte, logo, version des identifiants). */
    public const EDITABLE = ['name', 'sector_id', 'description', 'website', 'contact_name', 'contact_email'];

    protected $guarded = ['id', 'user_id', 'logo_path', 'credentials_version', 'credentials_printed_at'];

    protected function casts(): array
    {
        return [
            'credentials_version' => 'integer',
            'credentials_printed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<Sector, $this> */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /** @return BelongsToMany<Filiere, $this> */
    public function filieres(): BelongsToMany
    {
        return $this->belongsToMany(Filiere::class, 'company_filiere');
    }

    /** @return BelongsToMany<Competence, $this> */
    public function competences(): BelongsToMany
    {
        return $this->belongsToMany(Competence::class, 'company_competence');
    }

    /** @return HasMany<CompanyRecruiter, $this> */
    public function recruiters(): HasMany
    {
        return $this->hasMany(CompanyRecruiter::class);
    }

    public function auditLabel(): string
    {
        return 'Entreprise : '.$this->name;
    }
}
