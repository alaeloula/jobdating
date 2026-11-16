<?php

namespace App\Models\Referentials;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Classe, nommée comme dans le fichier YouCode School Admin. La filière d'un apprenant est
 * toujours lue via sa classe.
 *
 * @property string $name
 * @property int $filiere_id
 * @property-read Filiere $filiere
 */
class SchoolClass extends ReferentialModel
{
    public const SUBJECT_NAME = 'Classe';

    protected $table = 'classes';

    public function displayLabel(): string
    {
        return $this->name;
    }

    /**
     * @return BelongsTo<Filiere, $this>
     */
    public function filiere(): BelongsTo
    {
        return $this->belongsTo(Filiere::class);
    }
}
