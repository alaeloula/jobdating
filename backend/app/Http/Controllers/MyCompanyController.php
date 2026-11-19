<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use App\Services\Companies\CompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fiche de l'entreprise connectée (ENT-08, décision du 19/11) : description et site web.
 * L'entreprise est TOUJOURS celle du compte authentifié (RG-ACC-02) ; tout identifiant reçu est ignoré.
 */
class MyCompanyController extends Controller
{
    public function __construct(private readonly CompanyService $companies) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->own($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $company = $this->own($request);
        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:2000'],
            'website' => ['nullable', 'string', 'max:255', 'url:http,https'],
        ], [
            'description.max' => 'La description est trop longue (2 000 caractères au plus).',
            'website.url' => 'Adresse de site invalide : elle doit commencer par http:// ou https://.',
            'website.max' => 'Adresse trop longue.',
        ]);

        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->present($this->companies->updateOwn($company, $data, $user))]);
    }

    private function own(Request $request): Company
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->currentRole() === Role::Entreprise, 403);

        return Company::query()->where('user_id', $user->getKey())->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Company $company): array
    {
        return [
            'name' => $company->name,
            'description' => $company->description,
            'website' => $company->website,
        ];
    }
}
