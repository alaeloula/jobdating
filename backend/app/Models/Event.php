<?php

namespace App\Models;

use App\Contracts\HasAuditLabel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Événement JobDating (ADM-05). Une journée, un seul par année scolaire, au plus un courant.
 * Le statut n'est jamais stocké : il est calculé en jours entiers, heure du Maroc (RG-EVT-02).
 *
 * @property int $id
 * @property string $name
 * @property string $school_year
 * @property Carbon $date
 * @property string $location
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $description
 * @property bool $is_current
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 */
class Event extends Model implements HasAuditLabel
{
    public const TIMEZONE = 'Africa/Casablanca';

    public const UPCOMING = 'a_venir';

    public const ONGOING = 'en_cours';

    public const FINISHED = 'termine';

    /** `school_year`, `is_current` et `archived_at` ne s'écrivent que par `EventService`. */
    protected $fillable = ['name', 'date', 'location', 'latitude', 'longitude', 'description'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_current' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /** Année scolaire d'une date : du 1er septembre au 31 août (décision du 23/11). */
    public static function schoolYearFor(CarbonInterface|string $date): string
    {
        $day = is_string($date) ? Carbon::parse($date) : $date;
        $start = $day->month >= 9 ? $day->year : $day->year - 1;

        return $start.'/'.($start + 1);
    }

    public static function today(): string
    {
        return now(self::TIMEZONE)->toDateString();
    }

    /** Nom affiché partout : nom suivi de l'année scolaire. */
    public function fullName(): string
    {
        return $this->name.' '.$this->school_year;
    }

    public function status(): string
    {
        $date = $this->date->toDateString();
        $today = self::today();

        return match (true) {
            $date > $today => self::UPCOMING,
            $date === $today => self::ONGOING,
            default => self::FINISHED,
        };
    }

    public function isFinished(): bool
    {
        return $this->status() === self::FINISHED;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Jour de création, heure du Maroc : borne de RG-EVT-04. */
    public function createdOn(): string
    {
        return $this->created_at->copy()->setTimezone(self::TIMEZONE)->toDateString();
    }

    /**
     * Passe par l'index unique de `current_marker`.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNotNull('current_marker');
    }

    /** @return HasMany<EventCompany, $this> */
    public function companyParticipations(): HasMany
    {
        return $this->hasMany(EventCompany::class);
    }

    /** @return HasMany<EventLearner, $this> */
    public function learnerParticipations(): HasMany
    {
        return $this->hasMany(EventLearner::class);
    }

    public function auditLabel(): string
    {
        return 'Événement : '.$this->fullName();
    }
}
