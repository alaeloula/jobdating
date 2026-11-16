<?php

namespace App\Models\Referentials;

/**
 * @property string $name
 * @property string|null $city
 * @property string|null $contact_email
 */
class Campus extends ReferentialModel
{
    public const SUBJECT_NAME = 'Campus';

    protected $table = 'campuses';

    public function displayLabel(): string
    {
        return $this->name;
    }
}
