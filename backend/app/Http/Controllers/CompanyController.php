<?php

namespace App\Http\Controllers;

use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\User;
use App\Services\Companies\CompanyService;
use App\Services\Companies\UsernameSuggester;
use App\Services\Events\EventParticipationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Entreprises (ADM-02, T-11). Gestion : administrateur (`companies.manage`) ; lecture de la liste
 * et de la fiche, profils recherchés : administrateur et CME (`companies.profiles.manage`).
 */
class CompanyController extends Controller
{
    private const PER_PAGE = 25;

    private const RELATIONS = ['user', 'sector', 'filieres', 'competences', 'recruiters'];

    public function __construct(
        private readonly CompanyService $companies,
        private readonly EventParticipationService $participation,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['actif', 'desactive', 'non_imprimee'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Company::query()->with(self::RELATIONS);

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('name', 'like', $term)
                ->orWhereHas('user', fn (Builder $u) => $u->withTrashed()->where('username', 'like', $term)));
        }

        match ($filters['status'] ?? null) {
            'actif' => $query->whereHas('user', fn (Builder $u) => $u->whereNull('deactivated_at')),
            'desactive' => $query->whereHas('user', fn (Builder $u) => $u->whereNotNull('deactivated_at')),
            'non_imprimee' => $query->whereNull('credentials_printed_at'),
            default => null,
        };

        return CompanyResource::collection($query->orderBy('name')->orderBy('id')->paginate(self::PER_PAGE)->withQueryString());
    }

    public function show(int $id): CompanyResource
    {
        return CompanyResource::make($this->find($id));
    }

    public function usernameSuggestion(Request $request): JsonResponse
    {
        $name = (string) $request->validate(['name' => ['required', 'string', 'max:150']])['name'];

        return response()->json(['data' => ['username' => UsernameSuggester::suggest($name)]]);
    }

    public function store(Request $request): JsonResponse
    {
        // Les profils recherchés se saisissent APRÈS la création (décision du 19/11, revue Codex T-11,
        // constat 3) : envoyés ici, même vides, ils sont refusés plutôt qu'ignorés.
        $data = $request->validate($this->rules(true) + [
            'filiere_ids' => ['missing'],
            'competence_ids' => ['missing'],
            // Événement obligatoire (RG-ENTR-03) : éligibilité contrôlée sous verrou par le service.
            'event_id' => ['required', 'integer'],
            'floor' => ['nullable', 'string', 'max:50'],
            'table_label' => ['nullable', 'string', 'max:50'],
            'username' => ['required', 'string', 'min:'.UsernameSuggester::MIN, 'max:'.UsernameSuggester::MAX,
                'regex:'.UsernameSuggester::PATTERN, Rule::unique('users', 'username')],
        ], $this->messages());

        $eventId = (int) $data['event_id'];
        $created = $this->companies->create($this->normalize($data), $request->file('logo'), $this->actor($request), [
            'event_id' => $eventId,
            'floor' => $data['floor'] ?? null,
            'table_label' => $data['table_label'] ?? null,
        ]);
        $company = $created['company']->load(self::RELATIONS);

        return $this->withCredentials($company, $created['password'], 201, $this->participation->credentialsContext($company, $eventId));
    }

    public function update(Request $request, int $id): CompanyResource
    {
        $data = $request->validate($this->rules(false), $this->messages());

        return CompanyResource::make($this->companies->update($this->find($id), $this->normalize($data), $this->actor($request))->load(self::RELATIONS));
    }

    public function storeLogo(Request $request, int $id): CompanyResource
    {
        $request->validate(['logo' => ['required', ...$this->logoRules()]], $this->messages());
        /** @var UploadedFile $logo */
        $logo = $request->file('logo');

        return CompanyResource::make($this->companies->replaceLogo($this->find($id), $logo, $this->actor($request))->load(self::RELATIONS));
    }

    public function destroyLogo(Request $request, int $id): CompanyResource
    {
        return CompanyResource::make($this->companies->removeLogo($this->find($id), $this->actor($request))->load(self::RELATIONS));
    }

    /** Logo (stockage privé) : tout utilisateur qui peut consulter les entreprises. */
    public function logo(int $id): StreamedResponse
    {
        $company = Company::query()->findOrFail($id);
        abort_if($company->logo_path === null || ! Storage::disk(CompanyService::LOGO_DISK)->exists($company->logo_path), 404);

        return Storage::disk(CompanyService::LOGO_DISK)->response($company->logo_path, null, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function deactivate(int $id): CompanyResource
    {
        return CompanyResource::make($this->companies->deactivate($this->find($id))->load(self::RELATIONS));
    }

    public function reactivate(int $id): CompanyResource
    {
        return CompanyResource::make($this->companies->reactivate($this->find($id))->load(self::RELATIONS));
    }

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $eventId = $request->validate(['event_id' => ['nullable', 'integer']], ['event_id.integer' => 'Valeur invalide.'])['event_id'] ?? null;
        $company = $this->find($id);
        // Contexte contrôlé AVANT tout effet : un refus ne change ni le mot de passe ni sa version.
        $context = $this->participation->credentialsContext($company, $eventId === null ? null : (int) $eventId);
        $reset = $this->companies->resetPassword($company, $this->actor($request));

        return $this->withCredentials($reset['company']->load(self::RELATIONS), $reset['password'], 200, $context);
    }

    public function credentialsPrinted(Request $request, int $id): CompanyResource
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];

        return CompanyResource::make($this->companies->confirmPrinted($this->find($id), $version, $this->actor($request))->load(self::RELATIONS));
    }

    public function profiles(Request $request, int $id): CompanyResource
    {
        $data = $request->validate([
            'filiere_ids' => ['present', 'list'],
            'filiere_ids.*' => ['integer', 'distinct', Rule::exists('filieres', 'id')],
            'competence_ids' => ['present', 'list'],
            'competence_ids.*' => ['integer', 'distinct', Rule::exists('competences', 'id')],
        ], [
            '*.present' => 'La liste est obligatoire (vide si aucun profil).',
            '*.list' => 'La liste est invalide.',
            '*.*.distinct' => 'Une valeur est en double.',
            '*.*.exists' => 'Valeur inconnue.',
            '*.*.integer' => 'Valeur invalide.',
        ]);

        return CompanyResource::make($this->companies->updateProfiles(
            $this->find($id),
            array_map('intval', $data['filiere_ids']),
            array_map('intval', $data['competence_ids']),
            $this->actor($request),
        )->load(self::RELATIONS));
    }

    private function find(int $id): Company
    {
        return Company::query()->with(self::RELATIONS)->findOrFail($id);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * Fiche + identifiants affichés UNE seule fois (RG-ENTR-05) ; jamais en cache.
     *
     * @param  array<string, mixed>|null  $event  participation imprimée sur la fiche de connexion
     */
    private function withCredentials(Company $company, string $password, int $status, ?array $event): JsonResponse
    {
        return response()->json([
            'data' => CompanyResource::make($company)->resolve(),
            'credentials' => [
                'username' => $company->user?->username,
                'password' => $password,
                'version' => $company->credentials_version,
                'url' => config('app.frontend_url'),
                'event' => $event,
            ],
        ], $status)->header('Cache-Control', 'no-store, private');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        if (array_key_exists('sector_id', $data)) {
            $data['sector_id'] = $data['sector_id'] === null ? null : (int) $data['sector_id'];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:150'],
            'sector_id' => ['nullable', 'integer', Rule::exists('sectors', 'id')],
            'description' => ['nullable', 'string', 'max:2000'],
            'website' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'contact_email' => ['nullable', 'string', 'max:255', 'email:rfc'],
            'logo' => $creating ? ['nullable', ...$this->logoRules()] : ['prohibited'],
        ];
    }

    /**
     * @return list<string>
     */
    private function logoRules(): array
    {
        // Contenu réel vérifié (image), formats matriciels seulement (pas de SVG), 1 Mo, 2 000 px.
        return ['file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000'];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'required' => 'Ce champ est obligatoire.',
            'max' => 'Ce champ est trop long.',
            'website.url' => 'Adresse de site invalide : elle doit commencer par http:// ou https://.',
            'contact_email.email' => 'Adresse e-mail invalide.',
            'sector_id.exists' => 'Secteur inconnu.',
            'event_id.required' => 'Choisissez un événement.',
            'event_id.integer' => 'Choisissez un événement.',
            'username.regex' => 'Lettres minuscules, chiffres et tirets seulement (ex. societe-generale).',
            'username.min' => 'Au moins 3 caractères.',
            'username.max' => '50 caractères au plus.',
            'username.unique' => 'Ce nom d’utilisateur est déjà pris.',
            'logo.image' => 'Le logo doit être une image PNG, JPG ou WebP.',
            'logo.mimes' => 'Le logo doit être une image PNG, JPG ou WebP.',
            'logo.max' => 'Le logo ne doit pas dépasser 1 Mo.',
            'logo.dimensions' => 'Le logo ne doit pas dépasser 2 000 × 2 000 pixels.',
            'logo.prohibited' => 'Le logo se modifie séparément.',
            'filiere_ids.missing' => 'Les profils recherchés se saisissent après la création (« Profils recherchés »).',
            'competence_ids.missing' => 'Les profils recherchés se saisissent après la création (« Profils recherchés »).',
        ];
    }
}
