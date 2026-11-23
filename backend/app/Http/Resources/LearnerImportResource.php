<?php

namespace App\Http\Resources;

use App\Models\LearnerImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Import d'apprenants : LISTE BLANCHE de champs. Jamais `payload` ni `passwords` (plan T-13 v2, §6).
 *
 * @mixin LearnerImport
 */
class LearnerImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LearnerImport $import */
        $import = $this->resource;

        return [
            'id' => $import->id,
            'filename' => $import->filename,
            'status' => $import->status,
            'campus' => $import->campus_id,
            'promotion' => $import->promotion_id,
            'counts' => [
                'create' => $import->created_count,
                'update' => $import->updated_count,
                'unchanged' => $import->unchanged_count,
                'reject' => $import->rejected_count,
            ],
            'classes' => $import->class_counts ?? [],
            'rejections' => $import->rejections ?? [],
            'has_passwords' => $import->hasPasswords(),
            'passwords_deleted' => $import->passwords_deleted_at !== null,
            'error' => $import->error,
            'author' => $import->author !== null ? trim($import->author->first_name.' '.$import->author->last_name) : null,
            'created_at' => $import->created_at->toIso8601String(),
            'finished_at' => $import->finished_at?->toIso8601String(),
        ];
    }
}
