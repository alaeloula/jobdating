<?php

namespace Database\Seeders;

use App\Enums\AuditAction;
use App\Enums\EvaluationTagType;
use App\Models\Referentials\EvaluationTag;
use App\Models\Referentials\OpportunityType;
use App\Models\Referentials\ReferentialModel;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Données initiales des référentiels (T-02), utilisables en production et relançables :
 * opportunités validées (PC-23) et étiquettes initiales (ENT-05).
 *
 * Chaque valeur livrée a une clé fixe (`seed_key`). Pour chacune :
 * 1. une ligne porte déjà la clé : rien n'est fait (corrections faites à l'écran conservées) ;
 * 2. sinon, un homonyme sans clé existe (libellé ; type + libellé pour les étiquettes) : la clé
 *    lui est attribuée une seule fois, sans modifier ses champs métier, sans journal de création ;
 * 3. sinon, la valeur est créée en fin d'ordre et journalisée (auteur « Système »).
 *
 * Filières, classes, campus, promotions, secteurs et compétences : aucune donnée initiale
 * (saisies à l'écran par l'administrateur, décision du 16/11/2026).
 */
class ReferentialsSeeder extends Seeder
{
    /** @var list<array{key: string, label: string}> */
    public const OPPORTUNITIES = [
        ['key' => 'opportunity.stage_pre_embauche', 'label' => 'Stage de pré-embauche'],
        ['key' => 'opportunity.emploi', 'label' => 'Emploi (CDI / CDD)'],
        ['key' => 'opportunity.alternance', 'label' => 'Alternance'],
        ['key' => 'opportunity.freelance', 'label' => 'Freelance / mission'],
        ['key' => 'opportunity.autre', 'label' => 'Autre'],
    ];

    /** @var list<array{key: string, type: EvaluationTagType, label: string}> */
    public const TAGS = [
        ['key' => 'tag.strength.bonnes_bases', 'type' => EvaluationTagType::Strength, 'label' => 'Bonnes bases techniques'],
        ['key' => 'tag.strength.projets', 'type' => EvaluationTagType::Strength, 'label' => 'Projets personnels solides'],
        ['key' => 'tag.strength.relationnel', 'type' => EvaluationTagType::Strength, 'label' => 'Bon relationnel'],
        ['key' => 'tag.strength.curieux', 'type' => EvaluationTagType::Strength, 'label' => 'Curieux'],
        ['key' => 'tag.strength.anglais', 'type' => EvaluationTagType::Strength, 'label' => 'Bon niveau d\'anglais'],
        ['key' => 'tag.improvement.experience', 'type' => EvaluationTagType::Improvement, 'label' => 'Manque d\'expérience pratique'],
        ['key' => 'tag.improvement.cv', 'type' => EvaluationTagType::Improvement, 'label' => 'CV à améliorer'],
        ['key' => 'tag.improvement.presentation', 'type' => EvaluationTagType::Improvement, 'label' => 'Présentation à travailler'],
    ];

    public function run(AuditLogger $audit): void
    {
        foreach (self::OPPORTUNITIES as $item) {
            $this->ensure(
                OpportunityType::class,
                $item['key'],
                fn (Builder $q) => $q->where('label', $item['label']),
                ['label' => $item['label']],
                OpportunityType::query(),
                $audit,
            );
        }

        foreach (self::TAGS as $item) {
            $this->ensure(
                EvaluationTag::class,
                $item['key'],
                fn (Builder $q) => $q->where('type', $item['type']->value)->where('label', $item['label']),
                ['type' => $item['type']->value, 'label' => $item['label']],
                EvaluationTag::query()->where('type', $item['type']->value),
                $audit,
            );
        }
    }

    /**
     * @param  class-string<ReferentialModel>  $model
     * @param  callable(Builder<ReferentialModel>): Builder<ReferentialModel>  $homonym
     * @param  array<string, string>  $attributes
     * @param  Builder<ReferentialModel>  $group  valeurs du même groupe ordonné
     */
    private function ensure(string $model, string $key, callable $homonym, array $attributes, Builder $group, AuditLogger $audit): void
    {
        DB::transaction(function () use ($model, $key, $homonym, $attributes, $group, $audit): void {
            if ($model::query()->where('seed_key', $key)->lockForUpdate()->exists()) {
                return;
            }

            $existing = $homonym($model::query())->whereNull('seed_key')->lockForUpdate()->first();
            if ($existing !== null) {
                // Rattachement technique uniquement : aucun champ métier modifié, pas de journal.
                $model::query()->whereKey($existing->getKey())->update(['seed_key' => $key]);

                return;
            }

            $value = new $model;
            $value->fill($attributes);
            $value->active = true;
            $value->setAttribute('position', (int) (clone $group)->max('position') + 1);
            $value->setAttribute('seed_key', $key);
            $value->save();

            $audit->record(AuditAction::ReferentialCreated, $value, new: $attributes + ['active' => true]);
        });
    }
}
