<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CV courant d'un apprenant (CV-01, CDC 8.7) : un seul par apprenant, contenu chiffré sur le disque
 * privé. Le chemin et l'empreinte ne sortent jamais de l'API.
 *
 * @property int $id
 * @property int $learner_id
 * @property string $source
 * @property int|null $added_by
 * @property string|null $added_by_name
 * @property Carbon $added_at
 * @property string $path
 * @property string $sha256
 * @property string $version
 * @property int $size
 * @property string $mime
 * @property string|null $cme_reference
 */
class CvDocument extends Model
{
    public const SOURCE_UPLOAD = 'televerse';

    public const SOURCE_CME = 'plateforme_cme';

    protected $guarded = ['id'];

    protected $hidden = ['path', 'sha256'];

    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'added_by' => 'integer',
            'added_at' => 'datetime',
            'size' => 'integer',
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
