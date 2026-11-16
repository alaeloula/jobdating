<?php

namespace App\Models\Referentials;

/**
 * Type d'opportunité (ENT-05, rubrique 2 ; type final du suivi V2).
 *
 * @property string $label
 * @property int $position
 * @property string|null $seed_key
 */
class OpportunityType extends ReferentialModel
{
    public const SUBJECT_NAME = "Type d'opportunité";

    protected $table = 'opportunity_types';

    public function displayLabel(): string
    {
        return $this->label;
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'position' => 'integer'];
    }
}
