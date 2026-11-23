<?php

namespace App\Models;

use App\Contracts\HasAuditLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Import d'apprenants (ADM-04). `payload` et `passwords` sont chiffrés et jamais sérialisés.
 *
 * @property int $id
 * @property int $user_id
 * @property int $campus_id
 * @property int $promotion_id
 * @property string $filename
 * @property string $confirmation_key
 * @property string $fingerprint
 * @property string $status
 * @property int $created_count
 * @property int $updated_count
 * @property int $unchanged_count
 * @property int $rejected_count
 * @property list<array{class: string, create: int, update: int, unchanged: int}>|null $class_counts
 * @property list<array{line: int, reason: string}>|null $rejections
 * @property array<string, mixed>|null $payload
 * @property list<array{first_name: string, last_name: string, email: string, class: string, password: string}>|null $passwords
 * @property Carbon|null $passwords_deleted_at
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 */
class LearnerImport extends Model implements HasAuditLabel
{
    public const PENDING = 'en_attente';

    public const PROCESSING = 'en_cours';

    public const DONE = 'termine';

    public const FAILED = 'echec';

    /** États qu'un traitement, `failed()` ou la purge peuvent encore faire évoluer (plan v2.1, C2). */
    public const OPEN = [self::PENDING, self::PROCESSING];

    protected $guarded = ['id'];

    protected $hidden = ['payload', 'passwords'];

    protected function casts(): array
    {
        return [
            'rejections' => 'array',
            'class_counts' => 'array',
            'payload' => 'encrypted:array',
            'passwords' => 'encrypted:array',
            'passwords_deleted_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'unchanged_count' => 'integer',
            'rejected_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function hasPasswords(): bool
    {
        return $this->status === self::DONE && $this->passwords_deleted_at === null && $this->getRawOriginal('passwords') !== null;
    }

    public function auditLabel(): string
    {
        return 'Import d’apprenants n° '.$this->id.' ('.$this->filename.')';
    }
}
