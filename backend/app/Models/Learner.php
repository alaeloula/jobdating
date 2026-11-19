<?php

namespace App\Models;

use App\Contracts\HasAuditLabel;
use App\Models\Referentials\Campus;
use App\Models\Referentials\Competence;
use App\Models\Referentials\Promotion;
use App\Models\Referentials\SchoolClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Fiche apprenant (ADM-03). Le compte de connexion est `user` (rôle apprenant). La filière est
 * celle de la classe (RG-APR-01). La CNIE n'est jamais sérialisée par défaut (RG-APR-06).
 *
 * @property int $id
 * @property int $user_id
 * @property string $cnie
 * @property int $campus_id
 * @property int $promotion_id
 * @property int $class_id
 * @property string|null $cme_learner_id
 * @property-read User $user
 * @property-read Campus $campus
 * @property-read Promotion $promotion
 * @property-read SchoolClass $schoolClass
 */
class Learner extends Model implements HasAuditLabel
{
    /** Champs de la fiche modifiables par l'administrateur (l'identité est dans `users`). */
    public const EDITABLE = ['cnie', 'campus_id', 'promotion_id', 'class_id', 'cme_learner_id'];

    protected $guarded = ['id', 'user_id'];

    protected $hidden = ['cnie'];

    protected function casts(): array
    {
        return [
            'campus_id' => 'integer',
            'promotion_id' => 'integer',
            'class_id' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return BelongsTo<Promotion, $this> */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /** @return BelongsToMany<Competence, $this> */
    public function competences(): BelongsToMany
    {
        return $this->belongsToMany(Competence::class, 'learner_competence');
    }

    public function auditLabel(): string
    {
        $user = $this->user;

        return 'Apprenant : '.trim($user->first_name.' '.$user->last_name);
    }
}
