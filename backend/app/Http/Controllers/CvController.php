<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\CvDocument;
use App\Models\CvHistoryEntry;
use App\Models\Learner;
use App\Models\User;
use App\Services\Cv\CvRuleException;
use App\Services\Cv\CvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CV d'un apprenant (CV-01, CV-04, DECISIONS 26/11 ; plan T-20 v1.2, § 6). Routes du groupe apprenants
 * (rôles administrateur, CME, Direction) ; téléverser, retirer : `cv.upload` ; historique : `cv.history`.
 *
 * Apprenant supprimé (décision 10) : 404 pour la CME et la Direction, comme la fiche ; l'administrateur
 * reçoit 409 `learner_deleted` et seul lui voit l'historique.
 */
class CvController extends Controller
{
    private const HISTORY_PER_PAGE = 20;

    public function __construct(private readonly CvService $cvs) {}

    public function upload(Request $request, int $id): JsonResponse
    {
        $learner = $this->find($request, $id);
        $request->validate(
            ['file' => ['required', 'file', 'max:5120', 'extensions:pdf']],
            [
                'file.required' => 'Choisissez un fichier PDF.',
                'file.file' => CvService::INVALID_MESSAGE,
                'file.uploaded' => CvService::INVALID_MESSAGE,
                'file.max' => CvService::INVALID_MESSAGE,
                'file.extensions' => CvService::INVALID_MESSAGE,
            ],
        );

        try {
            $result = $this->cvs->upload($learner, $request->file('file'), $this->actor($request));
        } catch (CvRuleException $e) {
            return $this->refusal($e, $request);
        }

        return response()->json([
            'data' => self::state($result['document']),
            'status' => $result['status'],
            'unchanged' => $result['status'] === 'unchanged',
        ], $result['status'] === 'created' ? 201 : 200);
    }

    public function remove(Request $request, int $id): JsonResponse
    {
        try {
            $this->cvs->remove($this->find($request, $id), $this->actor($request));
        } catch (CvRuleException $e) {
            return $this->refusal($e, $request);
        }

        return response()->json(['data' => null]);
    }

    public function history(Request $request, int $id): JsonResponse
    {
        $learner = $this->find($request, $id);
        $page = CvHistoryEntry::query()->where('learner_id', $learner->id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(self::HISTORY_PER_PAGE, page: max(1, (int) $request->query('page', '1')));

        return response()->json([
            'data' => $page->getCollection()->map(fn (CvHistoryEntry $e) => [
                'id' => $e->id,
                'action' => $e->action,
                'source' => $e->source,
                'author' => $e->user_name,
                'size' => $e->size,
                'reason' => $e->reason,
                'created_at' => $e->created_at->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * État du CV pour l'API (jamais le chemin ni l'empreinte).
     *
     * @return array{source: string, added_at: string, added_by_name: string|null, size: int}
     */
    public static function state(CvDocument $cv): array
    {
        return [
            'source' => $cv->source,
            'added_at' => $cv->added_at->toIso8601String(),
            'added_by_name' => $cv->added_by_name,
            'size' => $cv->size,
        ];
    }

    /**
     * Apprenant supprimé PENDANT l'opération (découvert sous le verrou du compte) : même règle que la
     * fiche (décision 10) — 404 pour la CME et la Direction, 409 pour l'administrateur (revue Codex T-20, constat 4).
     */
    private function refusal(CvRuleException $e, Request $request): JsonResponse
    {
        if ($e->errorCode === 'learner_deleted' && $this->actor($request)->currentRole() !== Role::Administrateur) {
            abort(404);
        }

        return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
    }

    /** Même règle que la fiche (LearnerController::find) : un supprimé n'existe que pour l'administrateur. */
    private function find(Request $request, int $id): Learner
    {
        $learner = Learner::query()->with('user')->findOrFail($id);
        abort_if($learner->user->trashed() && $this->actor($request)->currentRole() !== Role::Administrateur, 404);

        return $learner;
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
