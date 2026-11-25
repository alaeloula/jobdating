<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Participation d'une entreprise à un événement, avec son emplacement facultatif (RG-PAR-02).
 *
 * @property int $id
 * @property int $event_id
 * @property int $company_id
 * @property string|null $floor
 * @property string|null $table_label
 * @property-read Event $event
 * @property-read Company $company
 */
class EventCompany extends Model
{
    protected $table = 'event_companies';

    protected $fillable = ['event_id', 'company_id', 'floor', 'table_label'];

    protected function casts(): array
    {
        return ['event_id' => 'integer', 'company_id' => 'integer'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
