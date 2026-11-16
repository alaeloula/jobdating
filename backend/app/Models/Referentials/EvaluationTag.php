<?php

namespace App\Models\Referentials;

use App\Enums\EvaluationTagType;

/**
 * Étiquette d'évaluation (ENT-05, rubrique 4). Ordre propre à chaque type.
 *
 * @property EvaluationTagType $type
 * @property string $label
 * @property int $position
 * @property string|null $seed_key
 */
class EvaluationTag extends ReferentialModel
{
    public const SUBJECT_NAME = "Étiquette d'évaluation";

    protected $table = 'evaluation_tags_ref';

    public function displayLabel(): string
    {
        return $this->label.' ('.$this->type->label().')';
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'position' => 'integer', 'type' => EvaluationTagType::class];
    }
}
