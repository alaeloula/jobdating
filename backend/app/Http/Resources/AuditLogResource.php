<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AuditLog $log */
        $log = $this->resource;

        return [
            'id' => $log->id,
            'created_at' => $log->created_at->toIso8601String(),
            'actor' => $log->user_id !== null || $log->actor_label !== null
                ? ['id' => $log->user_id, 'label' => $log->actor_label]
                : null,
            'action' => $log->action->value,
            'action_label' => $log->action->label(),
            'category' => $log->category->value,
            'category_label' => $log->category->label(),
            'subject' => $log->subject_type !== null
                ? ['type' => $log->subject_type, 'id' => $log->subject_id, 'label' => $log->subject_label]
                : null,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'ip' => $log->ip,
        ];
    }
}
