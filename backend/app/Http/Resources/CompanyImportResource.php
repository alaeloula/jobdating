<?php

namespace App\Http\Resources;

use App\Models\CompanyImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Import d'entreprises : LISTE BLANCHE de champs. Jamais `payload` ni `credentials`.
 *
 * @mixin CompanyImport
 */
class CompanyImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CompanyImport $import */
        $import = $this->resource;

        return [
            'id' => $import->id,
            'filename' => $import->filename,
            'status' => $import->status,
            'event' => ['id' => $import->event_id, 'name' => $import->event_name, 'deleted' => $import->event_id === null],
            'counts' => [
                'create' => $import->created_count,
                'existing' => $import->existing_count,
                'duplicate' => $import->duplicate_count,
                'reject' => $import->rejected_count,
                'unconfirmed' => $import->unconfirmed_count,
            ],
            'signaled' => $import->signaled ?? [],
            'has_credentials' => $import->hasCredentials(),
            'credentials_deleted' => $import->credentials_deleted_at !== null,
            'error' => $import->error,
            'author' => $import->author !== null ? trim($import->author->first_name.' '.$import->author->last_name) : null,
            'created_at' => $import->created_at->toIso8601String(),
            'finished_at' => $import->finished_at?->toIso8601String(),
        ];
    }
}
