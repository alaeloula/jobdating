<?php

namespace App\Models;

use App\Contracts\HasAuditLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Import d'entreprises (ADM-04, T-64). `payload` et `credentials` sont chiffrés et jamais sérialisés.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $event_id
 * @property string $event_name
 * @property string $filename
 * @property string $confirmation_key
 * @property string $fingerprint
 * @property string $status
 * @property int $created_count
 * @property int $existing_count
 * @property int $duplicate_count
 * @property int $rejected_count
 * @property int $unconfirmed_count
 * @property list<array{line: int, reason: string, name: string|null}>|null $signaled
 * @property array<string, mixed>|null $payload
 * @property list<array{company_id: int, user_id: int, name: string, username: string, password: string, version: int, hash: string}>|null $credentials
 * @property Carbon|null $credentials_deleted_at
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property-read Event|null $event
 * @property-read User|null $author
 */
class CompanyImport extends Model implements HasAuditLabel
{
    public const PENDING = 'en_attente';

    public const PROCESSING = 'en_cours';

    public const DONE = 'termine';

    public const FAILED = 'echec';

    public const OPEN = [self::PENDING, self::PROCESSING];

    protected $guarded = ['id'];

    protected $hidden = ['payload', 'credentials'];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'signaled' => 'array',
            'payload' => 'encrypted:array',
            'credentials' => 'encrypted:array',
            'credentials_deleted_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_count' => 'integer',
            'existing_count' => 'integer',
            'duplicate_count' => 'integer',
            'rejected_count' => 'integer',
            'unconfirmed_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function hasCredentials(): bool
    {
        return $this->status === self::DONE && $this->credentials_deleted_at === null && $this->getRawOriginal('credentials') !== null;
    }

    public function auditLabel(): string
    {
        return 'Import d’entreprises n° '.$this->id.' ('.$this->filename.')';
    }
}
