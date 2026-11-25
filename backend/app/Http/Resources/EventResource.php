<?php

namespace App\Http\Resources;

use App\Contracts\EventUsage;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Événement pour l'administrateur (ADM-05). Le statut est calculé à chaque lecture.
 *
 * @mixin Event
 */
class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Event $event */
        $event = $this->resource;
        $passages = app(EventUsage::class)->forEvent($event);

        return [
            'id' => $event->id,
            'name' => $event->name,
            'school_year' => $event->school_year,
            'full_name' => $event->fullName(),
            'date' => $event->date->toDateString(),
            'created_on' => $event->createdOn(),
            'location' => $event->location,
            'latitude' => $event->latitude,
            'longitude' => $event->longitude,
            'description' => $event->description,
            'is_current' => $event->is_current,
            'archived' => $event->isArchived(),
            'status' => $event->status(),
            'passages' => $passages,
            'date_locked' => $passages > 0,
        ];
    }
}
