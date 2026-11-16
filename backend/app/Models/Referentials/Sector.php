<?php

namespace App\Models\Referentials;

/**
 * @property string $label
 */
class Sector extends ReferentialModel
{
    public const SUBJECT_NAME = 'Secteur';

    protected $table = 'sectors';

    public function displayLabel(): string
    {
        return $this->label;
    }
}
