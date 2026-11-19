<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Resources\StaffAccountResource;
use App\Models\User;
use App\Services\Accounts\StaffAccountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Comptes de l'équipe (ADM-01, T-10) : administrateur seulement (`users.manage`, CDC 3.2).
 * Un compte entreprise ou apprenant est hors périmètre : traité comme inexistant (RG-ACC-02).
 */
class StaffAccountController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly StaffAccountService $accounts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in($this->roleValues())],
            'status' => ['nullable', Rule::in(['actif', 'invitation', 'desactive', 'supprime'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = StaffAccountService::query()->with('roles');

        // Pas de empty() : la recherche « 0 » est une vraie recherche (revue Codex T-10, constat 4).
        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('first_name', 'like', $term)
                ->orWhere('last_name', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$term]));
        }

        if (! empty($filters['role'])) {
            $query->whereHas('roles', fn (Builder $q) => $q->where('name', $filters['role']));
        }

        match ($filters['status'] ?? null) {
            'supprime' => $query->whereNotNull('deleted_at'),
            'desactive' => $query->whereNull('deleted_at')->whereNotNull('deactivated_at'),
            'invitation' => $query->whereNull('deleted_at')->whereNull('deactivated_at')->whereNull('activated_at'),
            'actif' => $query->whereNull('deleted_at')->whereNull('deactivated_at')->whereNotNull('activated_at'),
            // Par défaut, les comptes supprimés n'apparaissent pas (filtre « Supprimés » pour les retrouver).
            default => $query->whereNull('deleted_at'),
        };

        $accounts = $query->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return StaffAccountResource::collection($accounts);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(null), $this->messages());

        $user = $this->accounts->create($data, $this->admin($request));

        return StaffAccountResource::make($user->load('roles'))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $id): StaffAccountResource
    {
        $target = $this->find($id);
        $data = $request->validate($this->rules($target), $this->messages());

        return StaffAccountResource::make($this->accounts->update($target, $data, $this->admin($request))->load('roles'));
    }

    public function deactivate(Request $request, int $id): StaffAccountResource
    {
        return StaffAccountResource::make($this->accounts->deactivate($this->find($id), $this->admin($request))->load('roles'));
    }

    public function reactivate(int $id): StaffAccountResource
    {
        return StaffAccountResource::make($this->accounts->reactivate($this->find($id))->load('roles'));
    }

    public function destroy(Request $request, int $id): Response
    {
        $this->accounts->delete($this->find($id), $this->admin($request));

        return response()->noContent();
    }

    public function restore(int $id): StaffAccountResource
    {
        return StaffAccountResource::make($this->accounts->restore($this->find($id))->load('roles'));
    }

    public function resendActivation(Request $request, int $id): JsonResponse
    {
        $this->accounts->resendActivation($this->find($id), $this->admin($request));

        return response()->json(['message' => 'Un nouveau lien d’activation va être envoyé.'], 202);
    }

    /** Mot de passe généré, renvoyé UNE seule fois (jamais relisible ensuite). */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $password = $this->accounts->resetPassword($this->find($id), $this->admin($request), $request->session()->getId());

        return response()->json(['data' => ['password' => $password]])->header('Cache-Control', 'no-store, private');
    }

    private function find(int $id): User
    {
        return StaffAccountService::query()->whereKey($id)->firstOrFail();
    }

    private function admin(Request $request): User
    {
        /** @var User $admin */
        $admin = $request->user();

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?User $current): array
    {
        $required = $current === null ? 'required' : 'sometimes';

        return [
            'first_name' => [$required, 'string', 'max:100'],
            'last_name' => [$required, 'string', 'max:100'],
            // RG-USR-01 : unique, comptes supprimés compris (un compte supprimé se restaure).
            'email' => [$required, 'string', 'max:255', 'email:rfc', Rule::unique('users', 'email')->ignore($current?->getKey())],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => [$required, Rule::in($this->roleValues())],
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
            'email.email' => 'Adresse e-mail invalide.',
            'email.unique' => 'Cette adresse e-mail est déjà utilisée (y compris par un compte supprimé : restaurez-le).',
            'role.in' => 'Rôle invalide : CME, Direction ou administrateur.',
        ];
    }

    /**
     * @return list<string>
     */
    private function roleValues(): array
    {
        return array_map(fn (Role $r) => $r->value, StaffAccountService::ROLES);
    }
}
