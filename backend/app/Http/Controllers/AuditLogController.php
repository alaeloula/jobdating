<?php

namespace App\Http\Controllers;

use App\Enums\AuditCategory;
use App\Http\Requests\AuditLogIndexRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Consultation du journal d'audit (TRA-01), réservée à la permission audit.view (administrateur).
 */
class AuditLogController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    public function index(AuditLogIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $query = AuditLog::query()
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d)->endOfDay()))
            ->when($filters['subject_type'] ?? null, fn ($q, $t) => $q->where('subject_type', $t)->where('subject_id', $filters['subject_id']))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);

        return AuditLogResource::collection($query->paginate($perPage)->withQueryString());
    }

    /**
     * Auteurs présents dans le journal (filtre « Auteur ») et catégories (filtres rapides).
     */
    public function filters(): JsonResponse
    {
        $authors = AuditLog::query()
            ->whereNotNull('user_id')
            ->selectRaw('user_id, MAX(actor_label) AS label')
            ->groupBy('user_id')
            ->orderBy('label')
            ->get()
            ->map(fn (AuditLog $row) => ['id' => $row->user_id, 'label' => $row->getAttribute('label')]);

        $categories = array_map(
            fn (AuditCategory $c) => ['value' => $c->value, 'label' => $c->label()],
            AuditCategory::cases(),
        );

        return response()->json(['data' => ['authors' => $authors, 'categories' => $categories]]);
    }
}
