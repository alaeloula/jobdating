<?php

namespace Tests\Feature\Referentials;

use App\Contracts\ClassUsage;
use App\Contracts\TagUsage;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Referentials\EvaluationTag;
use App\Models\Referentials\Filiere;
use App\Models\Referentials\OpportunityType;
use App\Models\Referentials\SchoolClass;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Référentiels (ADM-09, T-02) : droits, validation, absence de suppression, règles propres
 * (code de filière, filière d'une classe, type d'étiquette), ordre, lecture, journal.
 */
class ReferentialManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->withRole(Role::Administrateur)->create();
        $this->withHeader('Origin', 'http://localhost');
    }

    private function filiere(string $code = 'JAVA', string $label = 'Java / Spring'): Filiere
    {
        $this->actingAs($this->admin, 'web')
            ->postJson('/api/v1/referentials/filieres', ['code' => $code, 'label' => $label])
            ->assertCreated();

        return Filiere::query()->where('code', $code)->firstOrFail();
    }

    private function fakeClassUsage(int $count): void
    {
        $this->app->instance(ClassUsage::class, new class($count) implements ClassUsage
        {
            public function __construct(private int $count) {}

            public function learnersCount(SchoolClass $class): int
            {
                return $this->count;
            }
        });
    }

    private function fakeTagUsage(bool $used): void
    {
        $this->app->instance(TagUsage::class, new class($used) implements TagUsage
        {
            public function __construct(private bool $used) {}

            public function isUsed(EvaluationTag $tag): bool
            {
                return $this->used;
            }
        });
    }

    // ---------------------------------------------------------------- Droits

    /**
     * @return iterable<string, array{Role}>
     */
    public static function nonManagers(): iterable
    {
        yield 'direction' => [Role::Direction];
        yield 'entreprise' => [Role::Entreprise];
        yield 'apprenant' => [Role::Apprenant];
    }

    #[DataProvider('nonManagers')]
    public function test_gestion_refusee_sur_chaque_operation_mais_options_lisibles(Role $role): void
    {
        // Créée directement : aucune requête administrateur ne précède celles du rôle testé.
        $filiere = Filiere::query()->create(['code' => 'JAVA', 'label' => 'Java / Spring', 'active' => true]);
        $user = User::factory()->withRole($role)->create();
        $this->actingAs($user, 'web');

        $this->getJson('/api/v1/referentials/filieres')->assertForbidden();
        $this->postJson('/api/v1/referentials/filieres', ['code' => 'PHP', 'label' => 'PHP'])->assertForbidden();
        $this->patchJson("/api/v1/referentials/filieres/{$filiere->id}", ['label' => 'Autre'])->assertForbidden();
        $this->patchJson("/api/v1/referentials/filieres/{$filiere->id}", ['active' => false])->assertForbidden();
        $this->putJson('/api/v1/referentials/opportunites/order', ['ids' => [1]])->assertForbidden();

        $this->getJson('/api/v1/referentials/filieres/options')->assertOk()->assertJsonPath('data.0.code', 'JAVA');
        $this->assertTrue($filiere->fresh()?->active);
        $this->assertSame('Java / Spring', $filiere->fresh()?->label);
    }

    public function test_sans_session(): void
    {
        $this->getJson('/api/v1/referentials/filieres/options')->assertUnauthorized();
        $this->getJson('/api/v1/referentials/filieres')->assertUnauthorized();
    }

    public function test_la_cme_gere_les_referentiels(): void
    {
        $cme = User::factory()->withRole(Role::Cme)->create();

        $this->actingAs($cme, 'web')
            ->postJson('/api/v1/referentials/secteurs', ['label' => 'Banque'])
            ->assertCreated()
            ->assertJsonPath('data.label', 'Banque')
            ->assertJsonPath('data.active', true);
    }

    public function test_type_inconnu_et_absence_de_suppression(): void
    {
        $filiere = $this->filiere();

        $this->getJson('/api/v1/referentials/inconnu')->assertNotFound();
        $this->deleteJson("/api/v1/referentials/filieres/{$filiere->id}")->assertStatus(405);
        $this->assertNotNull($filiere->fresh());
    }

    // ------------------------------------------------------- Création et validation

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function simpleTypes(): iterable
    {
        yield 'campus' => ['campus', ['name' => 'Youssoufia', 'city' => 'Youssoufia', 'contact_email' => 'campus@example.test']];
        yield 'promotions' => ['promotions', ['label' => '2026']];
        yield 'secteurs' => ['secteurs', ['label' => 'Informatique']];
        yield 'competences' => ['competences', ['label' => '.NET']];
        yield 'opportunites' => ['opportunites', ['label' => 'Stage d\'été']];
        yield 'etiquettes' => ['etiquettes', ['type' => 'point_fort', 'label' => 'Rigoureux']];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('simpleTypes')]
    public function test_ajout_liste_et_unicite(string $type, array $payload): void
    {
        $this->actingAs($this->admin, 'web');

        $this->postJson("/api/v1/referentials/{$type}", $payload)->assertCreated();
        $this->getJson("/api/v1/referentials/{$type}")->assertOk()->assertJsonCount(1, 'data');

        // Même valeur, casse différente : refusée (collation insensible à la casse).
        $duplicate = array_map(fn ($v) => is_string($v) && $v !== 'point_fort' ? mb_strtoupper($v) : $v, $payload);
        $this->postJson("/api/v1/referentials/{$type}", $duplicate)->assertStatus(422);
    }

    public function test_champs_obligatoires_et_valeurs_invalides(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->postJson('/api/v1/referentials/filieres', [])->assertStatus(422)->assertJsonValidationErrors(['code', 'label']);
        $this->postJson('/api/v1/referentials/filieres', ['code' => 'J A', 'label' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->postJson('/api/v1/referentials/campus', ['name' => 'X', 'contact_email' => 'pas-un-email'])->assertStatus(422)->assertJsonValidationErrors(['contact_email']);
        $this->postJson('/api/v1/referentials/etiquettes', ['type' => 'autre', 'label' => 'X'])->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_une_etiquette_homonyme_est_permise_dans_l_autre_type(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->postJson('/api/v1/referentials/etiquettes', ['type' => 'point_fort', 'label' => 'Anglais'])->assertCreated();
        $this->postJson('/api/v1/referentials/etiquettes', ['type' => 'a_ameliorer', 'label' => 'Anglais'])->assertCreated();
        $this->postJson('/api/v1/referentials/etiquettes', ['type' => 'point_fort', 'label' => 'ANGLAIS'])->assertStatus(422);
    }

    // ---------------------------------------------------------------- Filière

    public function test_le_code_d_une_filiere_est_definitif(): void
    {
        $filiere = $this->filiere();

        $this->patchJson("/api/v1/referentials/filieres/{$filiere->id}", ['code' => 'J2EE'])
            ->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->patchJson("/api/v1/referentials/filieres/{$filiere->id}", ['code' => 'JAVA', 'label' => 'Java EE'])
            ->assertOk()->assertJsonPath('data.label', 'Java EE');

        $this->assertSame('JAVA', $filiere->fresh()?->code);
    }

    // ------------------------------------------------------- Filière d'une classe

    public function test_une_classe_exige_une_filiere_existante(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->postJson('/api/v1/referentials/classes', ['name' => 'Classe A'])->assertStatus(422)->assertJsonValidationErrors(['filiere_id']);
        $this->postJson('/api/v1/referentials/classes', ['name' => 'Classe A', 'filiere_id' => 999])->assertStatus(422)->assertJsonValidationErrors(['filiere_id']);
    }

    public function test_filiere_d_une_classe_non_utilisee_modifiable_directement(): void
    {
        $java = $this->filiere('JAVA');
        $js = $this->filiere('JS', 'JavaScript');
        $this->postJson('/api/v1/referentials/classes', ['name' => 'Classe A', 'filiere_id' => $java->id])->assertCreated();
        $class = SchoolClass::query()->firstOrFail();

        $this->patchJson("/api/v1/referentials/classes/{$class->id}", ['filiere_id' => $js->id])->assertOk();

        $this->assertSame($js->id, $class->fresh()?->filiere_id);
    }

    public function test_filiere_d_une_classe_utilisee_exige_une_confirmation_explicite(): void
    {
        $java = $this->filiere('JAVA');
        $js = $this->filiere('JS', 'JavaScript');
        $this->postJson('/api/v1/referentials/classes', ['name' => 'Classe A', 'filiere_id' => $java->id])->assertCreated();
        $class = SchoolClass::query()->firstOrFail();
        $this->fakeClassUsage(3);
        $logsBefore = AuditLog::query()->count();
        $url = "/api/v1/referentials/classes/{$class->id}";

        // Sans confirmation, ou false : 409, nombre recalculé par le serveur, rien de modifié.
        $this->patchJson($url, ['filiere_id' => $js->id, 'name' => 'Classe A2', 'learners_count' => 0])
            ->assertStatus(409)
            ->assertJsonPath('code', 'class_in_use')
            ->assertJsonPath('learners_count', 3);
        $this->patchJson($url, ['filiere_id' => $js->id, 'confirmer_changement_filiere' => false])->assertStatus(409);

        // Confirmation non booléenne : 422.
        $this->patchJson($url, ['filiere_id' => $js->id, 'confirmer_changement_filiere' => 'true'])->assertStatus(422);
        $this->patchJson($url, ['filiere_id' => $js->id, 'confirmer_changement_filiere' => 1])->assertStatus(422);

        $fresh = $class->fresh();
        $this->assertSame($java->id, $fresh?->filiere_id);
        $this->assertSame('Classe A', $fresh->name);
        $this->assertSame($logsBefore, AuditLog::query()->count());

        // Confirmation explicite : modification et journal.
        $this->patchJson($url, ['filiere_id' => $js->id, 'confirmer_changement_filiere' => true])->assertOk();
        $this->assertSame($js->id, $class->fresh()?->filiere_id);
        $log = AuditLog::query()->where('action', AuditAction::ReferentialUpdated->value)->latest('id')->first();
        $this->assertEquals(['filiere_id' => $java->id], $log?->old_values);
        $this->assertEquals(['filiere_id' => $js->id], $log->new_values);
    }

    // ---------------------------------------------------------------- Étiquettes

    public function test_type_d_une_etiquette_modifiable_seulement_si_non_utilisee(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->postJson('/api/v1/referentials/etiquettes', ['type' => 'a_ameliorer', 'label' => 'Existante'])->assertCreated();
        $this->postJson('/api/v1/referentials/etiquettes', ['type' => 'point_fort', 'label' => 'Rigoureux'])->assertCreated();
        $tag = EvaluationTag::query()->where('label', 'Rigoureux')->firstOrFail();

        $this->fakeTagUsage(true);
        $this->patchJson("/api/v1/referentials/etiquettes/{$tag->id}", ['type' => 'a_ameliorer'])
            ->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->assertSame('point_fort', $tag->fresh()?->type->value);

        $this->fakeTagUsage(false);
        $this->patchJson("/api/v1/referentials/etiquettes/{$tag->id}", ['type' => 'a_ameliorer'])->assertOk();
        $fresh = $tag->fresh();
        $this->assertSame('a_ameliorer', $fresh?->type->value);
        $this->assertSame(2, $fresh->position); // placée en fin de son nouveau groupe
    }

    /** Revue Codex T-02, constat 1 : unicité du couple effectif lors d'une modification partielle. */
    public function test_changement_de_type_seul_vers_un_homonyme_refuse_proprement(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->postJson('/api/v1/referentials/etiquettes', ['type' => 'point_fort', 'label' => 'Anglais'])->assertCreated();
        $this->postJson('/api/v1/referentials/etiquettes', ['type' => 'a_ameliorer', 'label' => 'Anglais'])->assertCreated();
        $this->postJson('/api/v1/referentials/etiquettes', ['type' => 'point_fort', 'label' => 'Ponctuel'])->assertCreated();
        $tag = EvaluationTag::query()->where('type', 'point_fort')->where('label', 'Anglais')->firstOrFail();
        $other = EvaluationTag::query()->where('label', 'Ponctuel')->firstOrFail();

        // Type seul vers l'autre groupe où le libellé existe déjà : 422 (et non une erreur 500).
        $this->patchJson("/api/v1/referentials/etiquettes/{$tag->id}", ['type' => 'a_ameliorer'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type'])
            ->assertJsonPath('errors.type.0', 'Une étiquette de ce type porte déjà ce libellé.');
        $this->assertSame('point_fort', $tag->fresh()?->type->value);

        // Libellé seul vers un homonyme du même type : 422 ; de l'autre type seulement : permis.
        $this->patchJson("/api/v1/referentials/etiquettes/{$other->id}", ['label' => 'ANGLAIS'])->assertStatus(422)->assertJsonValidationErrors(['label']);
        $this->patchJson("/api/v1/referentials/etiquettes/{$other->id}", ['type' => 'a_ameliorer', 'label' => 'Ponctuel'])->assertOk();

        // Valeur inchangée renvoyée telle quelle : pas de conflit avec elle-même.
        $this->patchJson("/api/v1/referentials/etiquettes/{$tag->id}", ['type' => 'point_fort', 'label' => 'Anglais'])->assertOk();
    }

    // ------------------------------------------------------ État et lecture

    public function test_desactivation_reactivation_et_lecture(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->postJson('/api/v1/referentials/campus', ['name' => 'Youssoufia', 'contact_email' => 'campus@example.test'])->assertCreated();
        $this->postJson('/api/v1/referentials/campus', ['name' => 'Nador'])->assertCreated();
        $id = (int) $this->getJson('/api/v1/referentials/campus')->json('data.1.id'); // tri par nom : Youssoufia

        $this->patchJson("/api/v1/referentials/campus/{$id}", ['active' => false])->assertOk()->assertJsonPath('data.active', false);

        $options = $this->getJson('/api/v1/referentials/campus/options')->assertOk()->json('data');
        $this->assertSame(['Nador'], array_column($options, 'label'));
        $this->assertArrayNotHasKey('contact_email', $options[0]);

        $all = $this->getJson('/api/v1/referentials/campus/options?inactives=1')->assertOk()->json('data');
        $this->assertEquals([['label' => 'Nador', 'active' => true], ['label' => 'Youssoufia', 'active' => false]],
            array_map(fn ($o) => ['label' => $o['label'], 'active' => $o['active']], $all));

        $this->assertNotNull(AuditLog::query()->where('action', AuditAction::ReferentialDeactivated->value)->first());
        $this->patchJson("/api/v1/referentials/campus/{$id}", ['active' => true])->assertOk();
        $this->assertNotNull(AuditLog::query()->where('action', AuditAction::ReferentialReactivated->value)->first());
    }

    public function test_un_identifiant_absent_du_referentiel_donne_404(): void
    {
        $filiere = $this->filiere();

        $this->patchJson("/api/v1/referentials/promotions/{$filiere->id}", ['label' => 'x'])->assertNotFound();
        $this->assertSame('Java / Spring', $filiere->fresh()?->label);
    }

    // ---------------------------------------------------------------- Ordre

    public function test_reordonnancement_des_opportunites_et_des_etiquettes(): void
    {
        $this->actingAs($this->admin, 'web');
        foreach (['A', 'B', 'C'] as $label) {
            $this->postJson('/api/v1/referentials/opportunites', ['label' => $label])->assertCreated();
        }
        $this->postJson('/api/v1/referentials/etiquettes', ['type' => 'point_fort', 'label' => 'F1'])->assertCreated();
        $this->postJson('/api/v1/referentials/etiquettes', ['type' => 'point_fort', 'label' => 'F2'])->assertCreated();
        $this->postJson('/api/v1/referentials/etiquettes', ['type' => 'a_ameliorer', 'label' => 'M1'])->assertCreated();
        $ids = OpportunityType::query()->orderBy('position')->pluck('id')->all();
        $strength = EvaluationTag::query()->where('type', 'point_fort')->orderBy('position')->pluck('id')->all();
        $improvement = EvaluationTag::query()->where('type', 'a_ameliorer')->pluck('id')->all();

        $this->putJson('/api/v1/referentials/opportunites/order', ['ids' => [$ids[2], $ids[0], $ids[1]]])->assertNoContent();
        $this->assertSame(['C', 'A', 'B'], array_column($this->getJson('/api/v1/referentials/opportunites')->json('data'), 'label'));

        // Incomplet, doublon, identifiant d'un autre groupe : refusés, ordre inchangé.
        $this->putJson('/api/v1/referentials/opportunites/order', ['ids' => [$ids[0], $ids[1]]])->assertStatus(422);
        $this->putJson('/api/v1/referentials/opportunites/order', ['ids' => [$ids[0], $ids[0], $ids[1]]])->assertStatus(422);
        $this->putJson('/api/v1/referentials/etiquettes/order', ['type' => 'point_fort', 'ids' => [$strength[1], $improvement[0]]])->assertStatus(422);
        $this->putJson('/api/v1/referentials/etiquettes/order', ['ids' => array_reverse($strength)])->assertStatus(422);
        $this->assertSame(['C', 'A', 'B'], array_column($this->getJson('/api/v1/referentials/opportunites')->json('data'), 'label'));

        $this->putJson('/api/v1/referentials/etiquettes/order', ['type' => 'point_fort', 'ids' => array_reverse($strength)])->assertNoContent();
        $this->assertSame(1, EvaluationTag::query()->find($strength[1])?->position);

        // Liste sans ordre : 404.
        $this->putJson('/api/v1/referentials/filieres/order', ['ids' => [1]])->assertNotFound();

        $log = AuditLog::query()->where('action', AuditAction::ReferentialReordered->value)->oldest('id')->first();
        $this->assertSame(['A', 'B', 'C'], $log?->old_values['ordre'] ?? null);
        $this->assertSame(['C', 'A', 'B'], $log->new_values['ordre'] ?? null);
    }

    /** Revue Codex T-02, constat 2 : l'ordre doit être une vraie liste JSON. */
    public function test_l_ordre_refuse_un_objet_json(): void
    {
        $this->actingAs($this->admin, 'web');
        foreach (['A', 'B'] as $label) {
            $this->postJson('/api/v1/referentials/opportunites', ['label' => $label])->assertCreated();
        }
        [$a, $b] = OpportunityType::query()->orderBy('position')->pluck('id')->all();
        $logsBefore = AuditLog::query()->count();

        $this->putJson('/api/v1/referentials/opportunites/order', ['ids' => ['a' => $b, 'b' => $a]])
            ->assertStatus(422)->assertJsonValidationErrors(['ids']);
        $this->putJson('/api/v1/referentials/opportunites/order', ['ids' => [10 => $b, 20 => $a]])
            ->assertStatus(422)->assertJsonValidationErrors(['ids']);

        $this->assertSame([1, 2], OpportunityType::query()->orderBy('id')->pluck('position')->all());
        $this->assertSame($logsBefore, AuditLog::query()->count());
    }

    /** Un échec d'écriture du journal annule la modification et le réordonnancement. */
    public function test_un_echec_du_journal_annule_la_modification_et_l_ordre(): void
    {
        // Données créées directement : la première requête doit résoudre le journal défaillant.
        $a = OpportunityType::query()->create(['label' => 'A', 'position' => 1, 'active' => true]);
        $b = OpportunityType::query()->create(['label' => 'B', 'position' => 2, 'active' => true]);
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            /**
             * @param  array<string, mixed>  $old
             * @param  array<string, mixed>  $new
             */
            public function record(AuditAction $action, ?Model $subject = null, array $old = [], array $new = [], ?User $actor = null): AuditLog
            {
                throw new \RuntimeException('Journal indisponible.');
            }
        });
        $this->actingAs($this->admin, 'web');
        $logsBefore = AuditLog::query()->count();

        $this->patchJson("/api/v1/referentials/opportunites/{$a->id}", ['label' => 'A modifiée'])->assertStatus(500);
        $this->putJson('/api/v1/referentials/opportunites/order', ['ids' => [$b->id, $a->id]])->assertStatus(500);

        $this->assertSame('A', $a->fresh()?->label);
        $this->assertSame(1, $a->fresh()->position);
        $this->assertSame(2, $b->fresh()?->position);
        $this->assertSame($logsBefore, AuditLog::query()->count());
    }

    // ---------------------------------------------------------------- Journal

    public function test_journal_categorie_libelle_et_filtre(): void
    {
        $filiere = $this->filiere('JAVA', 'Java / Spring');
        $this->patchJson("/api/v1/referentials/filieres/{$filiere->id}", ['label' => 'Java EE'])->assertOk();

        $created = AuditLog::query()->where('action', AuditAction::ReferentialCreated->value)->firstOrFail();
        $this->assertSame('referentiels', $created->category->value);
        $this->assertSame('Filière : JAVA – Java / Spring', $created->subject_label);
        $this->assertSame($this->admin->id, $created->user_id);
        $this->assertEquals(['code' => 'JAVA', 'label' => 'Java / Spring', 'active' => true], $created->new_values);

        $updated = AuditLog::query()->where('action', AuditAction::ReferentialUpdated->value)->firstOrFail();
        $this->assertEquals(['label' => 'Java / Spring'], $updated->old_values);
        $this->assertEquals(['label' => 'Java EE'], $updated->new_values);

        $actions = array_column($this->getJson('/api/v1/audit-logs?category=referentiels')->assertOk()->json('data'), 'action');
        $this->assertEqualsCanonicalizing(['referential.created', 'referential.updated'], $actions);
    }
}
