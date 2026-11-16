<?php

namespace App\Models\Referentials;

/**
 * @property string $label
 */
class Competence extends ReferentialModel
{
    public const SUBJECT_NAME = 'Compétence';

    protected $table = 'competences';

    public function displayLabel(): string
    {
        return $this->label;
    }
}
