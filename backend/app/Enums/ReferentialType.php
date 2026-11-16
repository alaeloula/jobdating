<?php

namespace App\Enums;

use App\Models\Referentials\Campus;
use App\Models\Referentials\Competence;
use App\Models\Referentials\EvaluationTag;
use App\Models\Referentials\Filiere;
use App\Models\Referentials\OpportunityType;
use App\Models\Referentials\Promotion;
use App\Models\Referentials\ReferentialModel;
use App\Models\Referentials\SchoolClass;
use App\Models\Referentials\Sector;
use Illuminate\Validation\Rule;

/**
 * Les 8 référentiels (ADM-09 et décisions du 16/11/2026). La valeur sert dans l'adresse de l'API.
 */
enum ReferentialType: string
{
    case Filieres = 'filieres';
    case Classes = 'classes';
    case Campus = 'campus';
    case Promotions = 'promotions';
    case Secteurs = 'secteurs';
    case Competences = 'competences';
    case Opportunites = 'opportunites';
    case Etiquettes = 'etiquettes';

    /**
     * @return class-string<ReferentialModel>
     */
    public function model(): string
    {
        return match ($this) {
            self::Filieres => Filiere::class,
            self::Classes => SchoolClass::class,
            self::Campus => Campus::class,
            self::Promotions => Promotion::class,
            self::Secteurs => Sector::class,
            self::Competences => Competence::class,
            self::Opportunites => OpportunityType::class,
            self::Etiquettes => EvaluationTag::class,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Filieres => 'Filières',
            self::Classes => 'Classes',
            self::Campus => 'Campus',
            self::Promotions => 'Promotions',
            self::Secteurs => 'Secteurs',
            self::Competences => 'Compétences',
            self::Opportunites => 'Opportunités',
            self::Etiquettes => 'Étiquettes',
        };
    }

    /** Les valeurs ont un ordre d'affichage modifiable (opportunités ; étiquettes par type). */
    public function orderable(): bool
    {
        return $this === self::Opportunites || $this === self::Etiquettes;
    }

    /**
     * Champs modifiables par l'API (ni `seed_key`, ni `position`, gérés par le serveur).
     *
     * @return list<string>
     */
    public function editableFields(): array
    {
        return match ($this) {
            self::Filieres => ['code', 'label'],
            self::Classes => ['name', 'filiere_id'],
            self::Campus => ['name', 'city', 'contact_email'],
            self::Promotions, self::Secteurs, self::Competences, self::Opportunites => ['label'],
            self::Etiquettes => ['type', 'label'],
        };
    }

    /**
     * Règles de validation. $current : valeur modifiée (null à la création) ; $input : données
     * reçues (type d'étiquette pour l'unicité type + libellé).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function rules(?ReferentialModel $current = null, array $input = []): array
    {
        $creating = $current === null;
        $required = $creating ? 'required' : 'sometimes';
        $table = (new ($this->model()))->getTable();
        $unique = fn (string $column) => Rule::unique($table, $column)->ignore($current?->getKey());

        return match ($this) {
            self::Filieres => [
                'code' => [$required, 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/', $unique('code')],
                'label' => [$required, 'string', 'max:150'],
            ],
            self::Classes => [
                'name' => [$required, 'string', 'max:100', $unique('name')],
                'filiere_id' => [$required, 'integer', Rule::exists('filieres', 'id')],
            ],
            self::Campus => [
                'name' => [$required, 'string', 'max:150', $unique('name')],
                'city' => ['nullable', 'string', 'max:100'],
                'contact_email' => ['nullable', 'email', 'max:255'],
            ],
            self::Promotions, self::Secteurs, self::Competences, self::Opportunites => [
                'label' => [$required, 'string', 'max:150', $unique('label')],
            ],
            // Unicité du couple type + libellé EFFECTIF : le champ absent d'une modification
            // partielle reprend sa valeur actuelle (revue Codex T-02, constat 1).
            self::Etiquettes => [
                'type' => [$required, Rule::enum(EvaluationTagType::class), $unique('type')->where(
                    'label',
                    is_string($input['label'] ?? null)
                        ? $input['label']
                        : ($current instanceof EvaluationTag ? $current->label : ''),
                )],
                'label' => [$required, 'string', 'max:150', $unique('label')->where(
                    'type',
                    is_string($input['type'] ?? null)
                        ? $input['type']
                        : ($current instanceof EvaluationTag ? $current->type->value : ''),
                )],
            ],
        };
    }

    /**
     * Champs renvoyés par la lecture des options (saisie et filtres) : ni e-mail de campus,
     * ni clé technique.
     *
     * @return array<string, mixed>
     */
    public function option(ReferentialModel $value): array
    {
        $base = ['id' => $value->getKey(), 'label' => $value->displayLabel(), 'active' => $value->active];

        return match (true) {
            $value instanceof Filiere => $base + ['code' => $value->code, 'name' => $value->label],
            $value instanceof SchoolClass => $base + ['filiere_id' => $value->filiere_id],
            $value instanceof EvaluationTag => $base + ['type' => $value->type->value, 'name' => $value->label],
            default => $base,
        };
    }
}
