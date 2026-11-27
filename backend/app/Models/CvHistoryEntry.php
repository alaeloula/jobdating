<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Historique des ajouts de CV (CV-04, CDC 7.6), en ajout seul : téléversement, remplacement,
 * retrait (DECISIONS 26/11) et, à partir de T-22, résultats d'import. Donnée métier conservée
 * tant que l'apprenant existe (décision 11 du plan T-20).
 *
 * @property int $id
 * @property int $learner_id
 * @property string $action
 * @property string $source
 * @property int|null $user_id
 * @property string|null $user_name
 * @property string|null $sha256
 * @property int|null $size
 * @property string|null $reason
 * @property Carbon $created_at
 */
class CvHistoryEntry extends Model
{
    public const UPLOADED = 'televerse';

    public const REPLACED = 'remplace';

    public const REMOVED = 'retire';

    public const UPDATED_AT = null;

    protected $table = 'cv_history';

    protected $guarded = ['id'];

    protected $hidden = ['sha256'];

    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'user_id' => 'integer',
            'size' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
