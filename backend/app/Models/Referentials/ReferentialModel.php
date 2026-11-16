<?php

namespace App\Models\Referentials;

use App\Contracts\HasAuditLabel;
use Illuminate\Database\Eloquent\Model;

/**
 * Valeur d'un référentiel (ADM-09). Jamais supprimée : désactivée (décision du 16/11/2026).
 *
 * `seed_key` est réservée au serveur (données initiales) : non assignable en masse, jamais
 * sérialisée.
 *
 * @property int $id
 * @property bool $active
 */
abstract class ReferentialModel extends Model implements HasAuditLabel
{
    /** Nom de l'élément dans le journal d'audit (« Filière », « Classe »…). */
    public const SUBJECT_NAME = 'Référentiel';

    protected $guarded = ['id', 'seed_key', 'created_at', 'updated_at'];

    protected $hidden = ['seed_key'];

    /** Libellé affiché de la valeur. */
    abstract public function displayLabel(): string;

    public function auditLabel(): string
    {
        return static::SUBJECT_NAME.' : '.$this->displayLabel();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
