<?php

namespace App\Http\Requests;

use App\Enums\AuditCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres du journal d'audit. Pagination bornée (EXI-SEC-06) ; tri fixe par date décroissante.
 */
class AuditLogIndexRequest extends FormRequest
{
    public const MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        return true; // Droit vérifié par le middleware permission:audit.view.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['nullable', Rule::enum(AuditCategory::class)],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'subject_type' => ['nullable', 'string', 'max:50', 'regex:/^[a-z_]+$/', 'required_with:subject_id'],
            'subject_id' => ['nullable', 'integer', 'min:1', 'required_with:subject_type'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.*' => 'Catégorie inconnue.',
            'to.after_or_equal' => 'La date de fin doit suivre la date de début.',
            'from.*' => 'Date de début invalide (format AAAA-MM-JJ).',
            'to.*' => 'Date de fin invalide (format AAAA-MM-JJ).',
            'per_page.max' => 'Au plus '.self::MAX_PER_PAGE.' lignes par page.',
            'per_page.*' => 'Nombre de lignes par page invalide.',
            'page.*' => 'Numéro de page invalide.',
            'user_id.*' => 'Auteur invalide.',
            'subject_type.*' => 'Type d’élément invalide.',
            'subject_id.*' => 'Élément invalide.',
        ];
    }
}
