<?php

namespace App\Models\Referentials;

/**
 * @property string $label
 */
class Promotion extends ReferentialModel
{
    public const SUBJECT_NAME = 'Promotion';

    protected $table = 'promotions';

    public function displayLabel(): string
    {
        return $this->label;
    }
}
