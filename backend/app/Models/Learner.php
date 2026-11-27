<?php

namespace App\Models;

use App\Contracts\HasAuditLabel;
use App\Models\Referentials\Campus;
use App\Models\Referentials\Competence;
use App\Models\Referentials\Promotion;
use App\Models\Referentials\SchoolClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /**
     * CV courant (T-20) : un seul par apprenant.
     *
     * @return HasOne<CvDocument, $this>
     */
    public function cv(): HasOne
    {
        return $this->hasOne(CvDocument::class);
    }

    /** @return BelongsToMany<Competence, $this> */
    public function competences(): BelongsToMany
    {
        return $this->belongsToMany(Competence::class, 'learner_competence');
    }

    /**
     * Participant VISIBLE d'un événement (plan T-14, § 5) : ligne non retirée, compte actif et non
     * supprimé. Règle écrite une seule fois, à réutiliser par T-23 et les tâches suivantes.
     *
     * @param  Builder<Learner>  $query
     * @return Builder<Learner>
     */
    public function scopeParticipatingIn(Builder $query, int $eventId): Builder
    {
        return $query
            ->whereHas('user', fn (Builder $u) => $u->whereNull('deactivated_at')->whereNull('deleted_at'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('event_learners')
                ->whereColumn('event_learners.learner_id', 'learners.id')
                ->where('event_learners.event_id', $eventId)
                ->whereNull('event_learners.removed_at'));
    }

    public function auditLabel(): string
    {
        $user = $this->user;

        return 'Apprenant : '.trim($user->first_name.' '.$user->last_name);
    }
}
