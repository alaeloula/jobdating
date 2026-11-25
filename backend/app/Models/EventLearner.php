<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Inscription d'un apprenant à un événement. Un retrait garde la ligne avec `removed_at` : il n'est
 * annulé que par « Réinscrire » (décision 1 du 23/11).
 *
 * @property int $id
 * @property int $event_id
 * @property int $learner_id
 * @property Carbon $enrolled_at
 * @property Carbon|null $removed_at
 */
class EventLearner extends Model
{
    public $timestamps = false;

    protected $table = 'event_learners';

    protected $fillable = ['event_id', 'learner_id', 'enrolled_at', 'removed_at'];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'learner_id' => 'integer',
            'enrolled_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
