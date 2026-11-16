<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Ligne du journal d'audit (TRA-01). Non modifiable : seule la création est permise.
 * La base le garantit aussi (déclencheurs MySQL) ; la purge passe par AuditLogger::purge().
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $actor_label
 * @property AuditAction $action
 * @property AuditCategory $category
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $ip
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'category' => AuditCategory::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Journal d’audit non modifiable (TRA-01).');
        });

        static::deleting(function (): void {
            throw new LogicException('Journal d’audit : suppression interdite, seule la purge annuelle est permise.');
        });
    }
}
