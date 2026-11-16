<?php

namespace App\Enums;

/**
 * Type d'une étiquette d'évaluation (ENT-05, rubrique 4).
 */
enum EvaluationTagType: string
{
    case Strength = 'point_fort';
    case Improvement = 'a_ameliorer';

    public function label(): string
    {
        return match ($this) {
            self::Strength => 'Point fort',
            self::Improvement => 'Point à améliorer',
        };
    }
}
