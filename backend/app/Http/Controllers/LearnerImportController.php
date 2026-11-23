<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Http\Resources\LearnerImportResource;
use App\Models\LearnerImport;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Imports\LearnerImportService;
use App\Services\Imports\PasswordFileExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Import des apprenants (ADM-04, T-13). Toutes les routes : permission `learners.import`
 * (administrateur ; décision 13 du 20/11/2026).
 */
class LearnerImportController extends Controller
{
    public function __construct(
        private readonly LearnerImportService $imports,
        private readonly AuditLogger $audit,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(), $this->messages());
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $result = $this->imports->preview($file, (int) $data['campus_id'], (int) $data['promotion_id']);
        $plan = $result['plan'];

        return response()->json(['data' => [
            'campus' => $result['campus']->displayLabel(),
            'promotion' => $result['promotion']->displayLabel(),
            'lines' => count($plan['rows']),
            'counts' => $plan['counts'],
            'classes' => $plan['classes'],
            'rejections' => $plan['rejections'],
            'fingerprint' => $plan['fingerprint'],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + [
            'fingerprint' => ['required', 'string', 'size:64'],
            'confirmation_key' => ['required', 'uuid'],
        ], $this->messages());
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $import = $this->imports->confirm(
            $file, (int) $data['campus_id'], (int) $data['promotion_id'],
            (string) $data['fingerprint'], (string) $data['confirmation_key'], $this->actor($request),
        );

        return response()->json(['data' => LearnerImportResource::make($import->refresh()->load('author'))->resolve()], $import->wasRecentlyCreated ? 201 : 200);
    }

    public function index(): AnonymousResourceCollection
    {
        return LearnerImportResource::collection(LearnerImport::query()->with('author')->latest('id')->paginate(20));
    }

    public function show(int $id): LearnerImportResource
    {
        return LearnerImportResource::make(LearnerImport::query()->with('author')->findOrFail($id));
    }

    /** Fichier des mots de passe : Excel en clair, plusieurs téléchargements, chacun journalisé. */
    public function passwords(Request $request, int $id, PasswordFileExporter $exporter): Response
    {
        $import = LearnerImport::query()->findOrFail($id);
        abort_unless($import->hasPasswords(), 404);

        $bytes = $exporter->bytes($import);
        $this->audit->record(AuditAction::LearnerImportPasswordsDownloaded, $import, actor: $this->actor($request));

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="mots-de-passe-import-'.$import->id.'.xlsx"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroyPasswords(Request $request, int $id): JsonResponse
    {
        DB::transaction(function () use ($request, $id): void {
            $import = LearnerImport::query()->lockForUpdate()->findOrFail($id);
            abort_unless($import->hasPasswords(), 404);
            $import->forceFill(['passwords' => null, 'passwords_deleted_at' => now()])->save();
            $this->audit->record(AuditAction::LearnerImportPasswordsDeleted, $import, actor: $this->actor($request));
        });

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,xlsx'],
            'campus_id' => ['required', 'integer'],
            'promotion_id' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'required' => 'Ce champ est obligatoire.',
            'integer' => 'Valeur invalide.',
            'file.max' => 'Le fichier dépasse 5 Mo.',
            'file.extensions' => 'Format non pris en charge : déposez un fichier CSV ou Excel (.xlsx).',
            'file.file' => 'Fichier invalide.',
            'fingerprint.*' => 'Aperçu invalide : relancez l’aperçu.',
            'confirmation_key.*' => 'Confirmation invalide : relancez l’aperçu.',
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
