<?php

namespace App\Http\Controllers;

use App\Http\Resources\CompanyImportResource;
use App\Models\CompanyImport;
use App\Models\User;
use App\Services\Imports\CompanyImportCredentials;
use App\Services\Imports\CompanyImportService;
use App\Services\Imports\CompanyWorkbookReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;

/**
 * Import des entreprises (ADM-04, T-64). Toutes les routes : permission `companies.manage`
 * (administrateur). Les identifiants ne sont renvoyés que par `credentials`, jamais en cache.
 */
class CompanyImportController extends Controller
{
    public function __construct(
        private readonly CompanyImportService $imports,
        private readonly CompanyImportCredentials $credentials,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(), $this->messages());
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $result = $this->imports->preview($file, (int) $data['event_id']);
        $plan = $result['plan'];

        return response()->json(['data' => [
            'event' => ['id' => $result['event']->id, 'name' => $result['event']->fullName()],
            'counts' => $plan['counts'],
            'to_create' => array_values(array_map(
                fn (array $r) => ['line' => $r['line'], 'name' => $r['name'], 'username' => $r['username']],
                array_filter($plan['rows'], fn (array $r) => $r['decision'] === 'create'),
            )),
            'signaled' => $plan['signaled'],
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
        $import = $this->imports->confirm($file, (int) $data['event_id'], (string) $data['fingerprint'], (string) $data['confirmation_key'], $this->actor($request));

        return response()->json(['data' => CompanyImportResource::make($import->refresh()->load('author'))->resolve()], $import->wasRecentlyCreated ? 201 : 200);
    }

    public function index(): AnonymousResourceCollection
    {
        return CompanyImportResource::collection(CompanyImport::query()->with('author')->latest('id')->paginate(20));
    }

    public function show(int $id): CompanyImportResource
    {
        return CompanyImportResource::make(CompanyImport::query()->with('author')->findOrFail($id));
    }

    public function credentials(Request $request, int $id): JsonResponse
    {
        $import = CompanyImport::query()->findOrFail($id);
        abort_unless($import->hasCredentials(), 404);

        return response()->json(['data' => $this->credentials->sheets($import, $this->actor($request))])
            ->header('Cache-Control', 'no-store, private');
    }

    public function credentialsPrinted(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->credentials->markPrinted($id, $this->actor($request))]);
    }

    public function destroyCredentials(Request $request, int $id): JsonResponse
    {
        $this->credentials->delete($id, $this->actor($request));

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:5120', 'extensions:xlsx'],
            'event_id' => ['required', 'integer'],
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
            'event_id.required' => 'Choisissez un événement.',
            'file.max' => 'Le fichier dépasse 5 Mo.',
            'file.extensions' => 'Format non pris en charge : déposez le classeur Excel (.xlsx).',
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

    /** Noms attendus, affichés par l'écran. */
    public function expected(): JsonResponse
    {
        return response()->json(['data' => [
            'sheet' => CompanyWorkbookReader::SHEET,
            'name' => CompanyWorkbookReader::NAME_HEADER,
            'confirmation' => CompanyWorkbookReader::CONFIRMATION_HEADER,
        ]]);
    }
}
