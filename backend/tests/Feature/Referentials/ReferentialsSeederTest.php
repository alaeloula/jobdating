<?php

namespace Tests\Feature\Referentials;

use App\Enums\AuditAction;
use App\Enums\EvaluationTagType;
use App\Models\AuditLog;
use App\Models\Referentials\Campus;
use App\Models\Referentials\Competence;
use App\Models\Referentials\EvaluationTag;
use App\Models\Referentials\Filiere;
use App\Models\Referentials\OpportunityType;
use App\Models\Referentials\Promotion;
use App\Models\Referentials\SchoolClass;
use App\Models\Referentials\Sector;
use App\Services\AuditLogger;
use Database\Seeders\ReferentialsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Données initiales des référentiels : opportunités (PC-23) et étiquettes (ENT-05), clés fixes,
 * relance sans écrasement des corrections faites à l'écran, homonymes saisis à la main.
 */
class ReferentialsSeederTest extends TestCase
{
    use RefreshDatabase;

    private function createdLogs(): int
    {
        return AuditLog::query()->where('action', AuditAction::ReferentialCreated->value)->count();
    }

    public function test_valeurs_initiales_et_listes_officielles_vides(): void
    {
        $this->seed(ReferentialsSeeder::class);

        $this->assertSame(
            ['Stage de pré-embauche', 'Emploi (CDI / CDD)', 'Alternance', 'Freelance / mission', 'Autre'],
            OpportunityType::query()->orderBy('position')->pluck('label')->all(),
        );
        $this->assertSame(5, EvaluationTag::query()->where('type', 'point_fort')->count());
        $this->assertSame(3, EvaluationTag::query()->where('type', 'a_ameliorer')->count());
        $this->assertSame([1, 2, 3], EvaluationTag::query()->where('type', 'a_ameliorer')->orderBy('position')->pluck('position')->all());

        foreach ([Filiere::class, SchoolClass::class, Campus::class, Promotion::class, Sector::class, Competence::class] as $model) {
            $this->assertSame(0, $model::query()->count(), $model);
        }

        $this->assertSame(13, $this->createdLogs());
        $this->assertSame(AuditLogger::SYSTEM_LABEL, AuditLog::query()->where('action', AuditAction::ReferentialCreated->value)->value('actor_label'));
    }

    public function test_relance_sans_changement(): void
    {
        $this->seed(ReferentialsSeeder::class);
        $this->seed(ReferentialsSeeder::class);

        $this->assertSame(5, OpportunityType::query()->count());
        $this->assertSame(8, EvaluationTag::query()->count());
        $this->assertSame(13, $this->createdLogs());
    }

    public function test_corrections_faites_a_l_ecran_conservees_a_la_relance(): void
    {
        $this->seed(ReferentialsSeeder::class);
        $curieux = EvaluationTag::query()->where('label', 'Curieux')->firstOrFail();
        $autre = OpportunityType::query()->where('label', 'Autre')->firstOrFail();

        $curieux->forceFill(['label' => 'Curiosité', 'active' => false, 'position' => 9])->save();
        $autre->forceFill(['label' => 'Autre opportunité', 'position' => 1])->save();

        $this->seed(ReferentialsSeeder::class);

        $this->assertSame(8, EvaluationTag::query()->count());
        $this->assertSame(5, OpportunityType::query()->count());
        $this->assertNull(EvaluationTag::query()->where('label', 'Curieux')->first());
        $this->assertNull(OpportunityType::query()->where('label', 'Autre')->first());
        $fresh = $curieux->fresh();
        $this->assertSame('Curiosité', $fresh?->label);
        $this->assertFalse($fresh->active);
        $this->assertSame(9, $fresh->position);
        $this->assertSame(1, $autre->fresh()?->position);
        $this->assertSame(13, $this->createdLogs());
    }

    public function test_homonyme_saisi_a_la_main_rattache_puis_corrige(): void
    {
        $manual = EvaluationTag::query()->create(['type' => EvaluationTagType::Strength, 'label' => 'Curieux', 'position' => 7, 'active' => true]);

        $this->seed(ReferentialsSeeder::class);

        // Rattaché (clé attribuée), champs métier inchangés, pas de doublon ni de création journalisée.
        $this->assertSame(1, EvaluationTag::query()->where('label', 'Curieux')->count());
        $this->assertSame('tag.strength.curieux', $manual->fresh()?->seed_key);
        $this->assertSame(7, $manual->fresh()?->position);
        $this->assertSame(12, $this->createdLogs());

        // Corrigé à l'écran, puis relance : pas de réintroduction de « Curieux ».
        $manual->forceFill(['label' => 'Curiosité', 'active' => false, 'position' => 1])->save();
        $this->seed(ReferentialsSeeder::class);

        $this->assertNull(EvaluationTag::query()->where('label', 'Curieux')->first());
        $this->assertSame(8, EvaluationTag::query()->count());
        $this->assertSame('Curiosité', $manual->fresh()?->label);
        $this->assertSame(12, $this->createdLogs());
    }

    public function test_homonyme_dans_l_autre_type_sans_effet(): void
    {
        $other = EvaluationTag::query()->create(['type' => EvaluationTagType::Improvement, 'label' => 'Curieux', 'position' => 1, 'active' => true]);

        $this->seed(ReferentialsSeeder::class);

        $this->assertNull($other->fresh()?->seed_key);
        $this->assertSame(1, EvaluationTag::query()->where('type', 'point_fort')->where('label', 'Curieux')->count());
        $this->assertSame(13, $this->createdLogs());
    }

    public function test_la_cle_technique_n_est_jamais_exposee(): void
    {
        $this->seed(ReferentialsSeeder::class);

        $this->assertArrayNotHasKey('seed_key', OpportunityType::query()->firstOrFail()->toArray());
    }
}
