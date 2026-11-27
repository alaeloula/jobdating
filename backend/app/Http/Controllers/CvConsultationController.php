<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\CvDocument;
use App\Models\Learner;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Cv\CvAccess;
use App\Services\Cv\CvStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Throwable;

/**
 * Consultation du CV par lien temporaire, journalisée (CV-03, RG-CV-01 à 03, APP-03 ; plan T-21 v1.2).
 *
 * Le lien est RELATIF, signé, valable 15 minutes, lié au compte qui l'a demandé et à la VERSION du CV.
 * Le fichier exige aussi la session de ce compte ; les droits (`CvAccess`) sont relus au service.
 * Le PDF est toujours servi en pièce jointe, isolé (`sandbox`, `nosniff`) : il est affiché dans le
 * site par pdf.js, jamais par le lecteur du navigateur. Entreprise : T-24.
 */
class CvConsultationController extends Controller
{
    public const LINK_MINUTES = 15;

    public function __construct(private readonly CvAccess $access, private readonly CvStorage $storage, private readonly AuditLogger $audit) {}

    /** Lien pour l'administrateur, la CME et la Direction (groupe des routes apprenants). */
    public function link(Request $request, int $id): JsonResponse
    {
        $learner = Learner::query()->with(['user', 'cv'])->findOrFail($id);
        abort_unless($this->access->canRead($this->actor($request), $learner), 404);

        return $this->linkFor($learner, $this->actor($request));
    }

    /** État du CV de l'apprenant connecté (« Mon CV ») : jamais les données de la fiche administrative. */
    public function mine(Request $request): JsonResponse
    {
        $cv = $this->ownLearner($request)->cv;

        return response()->json(['data' => $cv === null ? null : [
            'version' => $cv->version,
            'source' => $cv->source,
            'added_at' => $cv->added_at->toIso8601String(),
        ]]);
    }

    public function mineLink(Request $request): JsonResponse
    {
        $learner = $this->ownLearner($request);
        abort_unless($this->access->canRead($this->actor($request), $learner), 404);

        return $this->linkFor($learner, $this->actor($request));
    }

    /**
     * Fichier PDF. Ordre : intégrité de la signature (403, jamais renouvelable) → expiration (410) →
     * compte du lien (403) → CV et version courants (404 `cv_outdated`) → droits relus (404) → lecture
     * (503) → journal → envoi. Un échec du journal empêche l'envoi.
     */
    public function file(Request $request, int $cv): Response|JsonResponse
    {
        $download = $request->query('download');
        if (! URL::hasCorrectSignature($request, false, ['download']) || ($download !== null && $download !== '1')) {
            return $this->refusal('Lien de CV invalide.', 'invalid_link', 403);
        }
        if (! URL::signatureHasNotExpired($request)) {
            return $this->refusal('Lien de CV expiré : rouvrez le CV.', 'link_expired', 410);
        }
        $viewer = $this->actor($request);
        if ((string) $viewer->id !== (string) $request->query('user')) {
            return $this->refusal('Lien de CV invalide.', 'invalid_link', 403);
        }

        $document = CvDocument::query()->find($cv);
        if ($document === null || ! hash_equals($document->version, (string) $request->query('version'))) {
            return $this->refusal('Ce CV a été remplacé ou retiré.', 'cv_outdated', 404);
        }
        $learner = Learner::query()->with('user')->findOrFail($document->learner_id);
        if (! $this->access->canRead($viewer, $learner)) {
            return $this->refusal('Ressource introuvable.', 'not_found', 404);
        }

        try {
            $pdf = $this->storage->read($document->path);
        } catch (Throwable) {
            Log::warning('CV illisible (fichier absent ou déchiffrement impossible).', ['cv_document_id' => $document->id]);

            return $this->refusal('CV momentanément inaccessible, réessayez.', 'cv_unavailable', 503);
        }

        $this->audit->record(
            $download === '1' ? AuditAction::CvDownloaded : AuditAction::CvViewed,
            $learner,
            new: ['version' => $document->version, 'source' => $document->source === CvDocument::SOURCE_CME ? 'Plateforme CME' : 'Téléversé'],
            actor: $viewer,
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', self::fileName($learner), 'CV.pdf'),
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /** Nom ASCII sûr : `CV-Prenom-Nom.pdf` (séparateurs et caractères de contrôle neutralisés). */
    public static function fileName(Learner $learner): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii($learner->user->first_name.' '.$learner->user->last_name)), '-');

        return $slug === '' ? 'CV.pdf' : 'CV-'.mb_substr($slug, 0, 100).'.pdf';
    }

    private function linkFor(Learner $learner, User $viewer): JsonResponse
    {
        $cv = $learner->cv;
        if ($cv === null) {
            return $this->refusal('CV non disponible.', 'no_cv', 404);
        }
        $expires = now()->addMinutes(self::LINK_MINUTES);
        $url = URL::temporarySignedRoute('cv.file', $expires, ['cv' => $cv->id, 'user' => $viewer->id, 'version' => $cv->version], false);

        return response()->json(['url' => $url, 'expires_at' => $expires->toIso8601String(), 'version' => $cv->version])
            ->header('Cache-Control', 'no-store, private');
    }

    private function ownLearner(Request $request): Learner
    {
        return Learner::query()->with(['user', 'cv'])->where('user_id', $this->actor($request)->id)->firstOrFail();
    }

    private function refusal(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
