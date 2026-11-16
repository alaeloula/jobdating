<?php

namespace App\Models\Referentials;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Filière : code stable (définitif après création) et libellé affiché.
 *
 * @property string $code
 * @property string $label
 */
class Filiere extends ReferentialModel
{
    public const SUBJECT_NAME = 'Filière';

    protected $table = 'filieres';

    public function displayLabel(): string
    {
        return $this->code.' – '.$this->label;
    }

    /**
     * @return HasMany<SchoolClass, $this>
     */
    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }
}
