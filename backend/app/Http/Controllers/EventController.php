<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\User;
use App\Services\Events\EventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Événements (ADM-05, T-14). Gestion : administrateur (`events.manage`). Options de création
 * d'entreprise : `companies.manage`. Résumé du courant pour l'écran CME : `locations.manage`.
 */
class EventController extends Controller
{
    private const REMINDER = 'Pensez à désactiver les apprenants qui ne participent plus (ADM-03) et à ajouter les entreprises participantes.';

    public function __construct(private readonly EventService $events) {}

    public function index(): AnonymousResourceCollection
    {
        return EventResource::collection(Event::query()->orderByDesc('date')->orderByDesc('id')->get());
    }

    public function show(int $id): EventResource
    {
        return EventResource::make(Event::query()->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true), $this->messages());
        $event = $this->events->create($data, $this->actor($request));

        return response()->json([
            'data' => EventResource::make($event->refresh())->resolve(),
            'reminder' => self::REMINDER,
        ], 201);
    }

    public function update(Request $request, int $id): EventResource
    {
        $event = Event::query()->findOrFail($id);
        $data = $request->validate($this->rules(false), $this->messages());

        return EventResource::make($this->events->update($event, $data, $this->actor($request))->refresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->events->delete(Event::query()->findOrFail($id), $this->actor($request));

        return response()->json(null, 204);
    }

    public function setCurrent(Request $request, int $id): JsonResponse
    {
        $result = $this->events->designate(Event::query()->findOrFail($id), $this->actor($request));

        return response()->json([
            'data' => EventResource::make($result['event']->refresh())->resolve(),
            'report' => $result['report'],
        ]);
    }

    public function unsetCurrent(Request $request): JsonResponse
    {
        $this->events->unsetCurrent($this->actor($request));

        return response()->json(null, 204);
    }

    public function archive(Request $request, int $id): EventResource
    {
        return EventResource::make($this->events->archive(Event::query()->findOrFail($id), $this->actor($request))->refresh());
    }

    public function unarchive(Request $request, int $id): EventResource
    {
        return EventResource::make($this->events->unarchive(Event::query()->findOrFail($id), $this->actor($request))->refresh());
    }

    /** Événements proposés à la création d'une entreprise : non archivés, non terminés. */
    public function options(): JsonResponse
    {
        $events = Event::query()->whereNull('archived_at')->where('date', '>=', Event::today())
            ->orderBy('date')->get()
            ->map(fn (Event $e) => ['id' => $e->id, 'name' => $e->fullName(), 'date' => $e->date->toDateString(), 'is_current' => $e->is_current])
            ->values();

        return response()->json(['data' => [
            'current_id' => $events->firstWhere('is_current', true)['id'] ?? null,
            'events' => $events,
        ]]);
    }

    public function currentSummary(): JsonResponse
    {
        $event = Event::query()->current()->first();

        return response()->json(['data' => $event === null ? null : [
            'id' => $event->id,
            'name' => $event->fullName(),
            'date' => $event->date->toDateString(),
            'location' => $event->location,
            'status' => $event->status(),
        ]]);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:150'],
            'date' => [$required, 'date_format:Y-m-d'],
            'location' => [$required, 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'required' => 'Ce champ est obligatoire.',
            'max' => 'Ce champ est trop long.',
            'date.date_format' => 'Date invalide.',
            'numeric' => 'Valeur invalide.',
            'between' => 'Coordonnée invalide.',
            'latitude.required_with' => 'Renseignez la latitude et la longitude, ou aucune des deux.',
            'longitude.required_with' => 'Renseignez la latitude et la longitude, ou aucune des deux.',
        ];
    }
}
