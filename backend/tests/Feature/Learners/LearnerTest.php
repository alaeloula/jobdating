<?php

namespace Tests\Feature\Learners;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Enums\PasswordTokenType;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Referentials\Campus;
use App\Models\Referentials\Competence;
use App\Models\Referentials\Filiere;
use App\Models\Referentials\Promotion;
use App\Models\Referentials\SchoolClass;
use App\Models\User;
use App\Notifications\GeneratedPasswordNotification;
use App\Services\AuditLogger;
use App\Services\Learners\LearnerService;
use App\Services\Passwords\PasswordTokenService;
use App\Support\Cnie;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Apprenants (ADM-03, RG-APR-01 à 07, T-12, plan v2.1). Toutes les données sont fictives.
 */
class LearnerTest extends TestCase
{
    use RefreshDatabase;

    private const CNIE = 'ZZ900001';

    private User $admin;

    private Campus $campus;

    private Promotion $promotion;

    private SchoolClass $class;

    private Filiere $java;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
        $this->admin = User::factory()->withRole(Role::Administrateur)->create();
        $this->campus = Campus::query()->create(['name' => 'Campus Fictif']);
        $this->promotion = Promotion::query()->create(['label' => 'Promotion Fictive']);
        $this->java = Filiere::query()->create(['code' => 'JAVA', 'label' => 'Java']);
        $this->class = SchoolClass::query()->create(['name' => 'Classe Fictive', 'filiere_id' => $this->java->id]);
        $this->withHeader('Origin', 'http://localhost');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'cnie' => 'zz 900001',
            'first_name' => 'Prénom',
            'last_name' => 'Fictif',
            'email' => 'prenom.fictif@example.test',
            'phone' => '0600000000',
            'campus_id' => $this->campus->id,
            'promotion_id' => $this->promotion->id,
            'class_id' => $this->class->id,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function create(array $extra = []): array
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->admin, 'web')->postJson('/api/v1/learners', $this->payload($extra))->assertCreated()->json();
    }

    private function learner(string $email = 'prenom.fictif@example.test'): Learner
    {
        return Learner::query()->whereHas('user', fn ($q) => $q->withTrashed()->where('email', $email))->firstOrFail();
    }

    private function as(Role $role): User
    {
        $this->app['auth']->forgetGuards();
        $user = User::factory()->withRole($role)->create();
        $this->actingAs($user, 'web');

        return $user;
    }

    // ---------------------------------------------------------------- Droits

    /** @return iterable<string, array{Role, int, int}> */
    public static function readers(): iterable
    {
        // rôle, statut de la recherche, statut de la création
        yield 'administrateur' => [Role::Administrateur, 200, 201];
        yield 'CME' => [Role::Cme, 200, 403];
        yield 'Direction' => [Role::Direction, 200, 403];
        yield 'entreprise' => [Role::Entreprise, 403, 403];
        yield 'apprenant' => [Role::Apprenant, 403, 403];
    }

    #[DataProvider('readers')]
    public function test_droits_par_role(Role $role, int $search, int $create): void
    {
        $this->create();
        $id = $this->learner()->id;
        $this->as($role);

        $this->postJson('/api/v1/learners/search', [])->assertStatus($search);
        $this->getJson("/api/v1/learners/{$id}")->assertStatus($search);
        $this->postJson('/api/v1/learners', $this->payload(['email' => 'autre@example.test', 'cnie' => 'ZZ900002']))->assertStatus($create);
        $this->postJson("/api/v1/learners/{$id}/reset-password")->assertStatus($create === 201 ? 200 : 403);
        $this->postJson('/api/v1/learners/bulk-deactivate', ['ids' => [$id], 'expected_eligible' => 1])->assertStatus($create === 201 ? 200 : 403);
        $competences = in_array($role, [Role::Administrateur, Role::Cme], true) ? 200 : 403;
        $this->putJson("/api/v1/learners/{$id}/competences", ['competence_ids' => []])->assertStatus($competences);
    }

    /**
     * Décision du 19/11 « CNIE visible par l'administrateur, la CME et la Direction » : CNIE complète
     * dans la liste et la fiche, recherche par CNIE ; le téléphone reste à l'administrateur.
     */
    public function test_cnie_complete_pour_les_trois_roles_telephone_administrateur_seulement(): void
    {
        $this->create();
        $id = $this->learner()->id;

        foreach ([Role::Administrateur, Role::Cme, Role::Direction] as $role) {
            if ($role === Role::Administrateur) {
                $this->app['auth']->forgetGuards();
                $this->actingAs($this->admin, 'web');
            } else {
                $this->as($role);
            }
            $this->postJson('/api/v1/learners/search', [])->assertOk()->assertJsonPath('data.0.cnie', self::CNIE);
            $show = $this->getJson("/api/v1/learners/{$id}")->assertOk()->assertJsonPath('data.cnie', self::CNIE);
            if ($role === Role::Administrateur) {
                $show->assertJsonPath('data.phone', '0600000000');
            } else {
                $this->assertArrayNotHasKey('phone', $show->json('data'));
                $this->assertStringNotContainsString('0600000000', $show->getContent());
            }
            // Recherche exacte par CNIE, normalisée.
            $this->postJson('/api/v1/learners/search', ['search' => 'zz 900001'])->assertOk()->assertJsonCount(1, 'data');
        }
        // Plus de ligne de journal par consultation (catégorie CNIE vide).
        $this->assertSame(0, AuditLog::query()->where('category', AuditCategory::Cnie)->count());
    }

    public function test_entreprise_ne_voit_jamais_la_cnie(): void
    {
        $this->create();
        $id = $this->learner()->id;
        $this->as(Role::Entreprise);

        $this->postJson('/api/v1/learners/search', ['search' => self::CNIE])->assertForbidden();
        $response = $this->getJson("/api/v1/learners/{$id}")->assertForbidden();
        $this->assertStringNotContainsString(self::CNIE, $response->getContent());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCnies(): iterable
    {
        yield 'trop court' => ['ZZ12'];
        yield 'tiret' => ['ZZ-90001'];
        // Le champ `cnie` est exclu de TrimStrings (revue Codex T-12, constat 5) : refusés par l'API.
        yield 'retour à la ligne final' => ["ZZ900001\n"];
        yield 'tabulation finale' => ["ZZ900001\t"];
        yield 'retour à la ligne au milieu' => ["ZZ90\n0001"];
        yield 'accent' => ['ZÉ900001'];
        yield 'trop long' => [str_repeat('A', 31)];
    }

    #[DataProvider('invalidCnies')]
    public function test_cnie_invalide_refusee_sans_repeter_la_valeur(string $cnie): void
    {
        $response = $this->actingAs($this->admin, 'web')->postJson('/api/v1/learners', $this->payload(['cnie' => $cnie]))
            ->assertStatus(422)->assertJsonValidationErrors('cnie');
        $this->assertStringNotContainsString(trim($cnie), $response->getContent());
        $this->assertSame(0, Learner::query()->count());
    }

    public function test_regle_cnie(): void
    {
        $this->assertSame('AB0012345', Cnie::normalize(' ab 0012345 '));
        $this->assertTrue(Cnie::isValid('AB0012345'));
        $this->assertFalse(Cnie::isValid("AB0012345\n"));
        $this->assertFalse(Cnie::isValid(Cnie::normalize("ab\t12345")));
    }

    public function test_cnie_unique_y_compris_fiche_supprimee_et_jamais_dans_les_erreurs(): void
    {
        $this->create();
        $this->actingAs($this->admin, 'web')->postJson('/api/v1/learners', $this->payload(['email' => 'b@example.test']))
            ->assertStatus(422)->assertJsonPath('errors.cnie.0', 'Cette CNIE est déjà enregistrée : modifiez plutôt la fiche existante.');

        $this->deleteJson('/api/v1/learners/'.$this->learner()->id)->assertNoContent();
        $response = $this->postJson('/api/v1/learners', $this->payload(['email' => 'b@example.test']))
            ->assertStatus(422)->assertJsonPath('errors.cnie.0', 'Cette CNIE appartient à une fiche supprimée : restaurez-la plutôt.');
        $this->assertStringNotContainsString(self::CNIE, $response->getContent());

        // Modification vers la CNIE d'un autre apprenant : refusée, sans la valeur.
        $this->postJson('/api/v1/learners', $this->payload(['email' => 'c@example.test', 'cnie' => 'ZZ900003']))->assertCreated();
        $other = $this->learner('c@example.test');
        $this->postJson("/api/v1/learners/{$other->id}/restore");
        $update = $this->putJson("/api/v1/learners/{$other->id}", ['cnie' => self::CNIE])->assertStatus(422);
        $this->assertStringNotContainsString(self::CNIE, $update->getContent());
    }

    public function test_cnie_jamais_en_clair_dans_le_journal(): void
    {
        $this->create();
        $id = $this->learner()->id;
        $this->putJson("/api/v1/learners/{$id}", ['cnie' => 'ZZ900009'])->assertOk();
        $this->postJson('/api/v1/learners/bulk-deactivate', ['filters' => ['search' => 'ZZ900009'], 'expected_eligible' => 1])->assertOk();

        $all = AuditLog::query()->get()->map(fn (AuditLog $l) => json_encode([$l->old_values, $l->new_values, $l->subject_label]))->implode(' ');
        $this->assertStringNotContainsString(self::CNIE, $all);
        $this->assertStringNotContainsString('ZZ900009', $all);
        $summary = AuditLog::query()->where('action', AuditAction::LearnerBulkDeactivated)->sole();
        $this->assertSame(['mode' => 'filtre', 'recherche' => 'oui'], $summary->new_values['selection']);
    }

    // ---------------------------------------------------------------- Création

    public function test_creation_compte_active_mot_de_passe_affiche_une_fois_et_e_mail_de_creation(): void
    {
        $response = $this->actingAs($this->admin, 'web')->postJson('/api/v1/learners', $this->payload())
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $password = (string) $response->json('password');
        $learner = $this->learner();
        $user = $learner->user;

        $this->assertSame(Role::Apprenant, $user->currentRole());
        $this->assertNotNull($user->activated_at);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertSame(self::CNIE, $learner->cnie);
        $this->assertSame('JAVA – Java', $response->json('data.filiere.label'));
        Notification::assertSentTo($user, GeneratedPasswordNotification::class, function (GeneratedPasswordNotification $n) use ($user, $password) {
            $mail = $n->toMail($user);

            return $n->reason === GeneratedPasswordNotification::REASON_CREATED
                && $mail->subject === 'Votre compte JobDating'
                && in_array($password, $mail->introLines, true)
                && ! str_contains(implode(' ', $mail->outroLines), 'sessions');
        });
        $this->assertStringNotContainsString($password, AuditLog::query()->get()->toJson());
        $this->postJson('/api/v1/learners/search', [])->assertOk()->assertJsonMissing(['password' => $password]);
    }

    public function test_creation_annulee_par_une_transaction_englobante_n_envoie_aucun_e_mail(): void
    {
        // Vraie file « database » (le faux de Notification ne tient pas compte de afterCommit).
        Notification::swap(new ChannelManager($this->app));
        config(['queue.default' => 'database']);

        rescue(fn () => DB::transaction(function (): void {
            app(LearnerService::class)->create($this->payload(), $this->admin);
            throw new RuntimeException('annulation');
        }), report: false);
        $this->assertSame(0, Learner::query()->count());
        $this->assertSame(0, DB::table('jobs')->count());

        // Témoin : validée, la création met bien l'e-mail en file (chiffré).
        DB::transaction(fn () => app(LearnerService::class)->create($this->payload(), $this->admin));
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertStringNotContainsString('Votre compte', (string) DB::table('jobs')->value('payload'));
    }

    public function test_referentiels_actifs_exiges_a_la_creation(): void
    {
        $this->class->forceFill(['active' => false])->save();
        $this->actingAs($this->admin, 'web')->postJson('/api/v1/learners', $this->payload())
            ->assertStatus(422)->assertJsonValidationErrors('class_id');
        $this->postJson('/api/v1/learners', $this->payload(['class_id' => 99999]))->assertStatus(422)->assertJsonValidationErrors('class_id');
        $this->assertSame(0, Learner::query()->count());
    }

    public function test_email_unique_supprimes_compris(): void
    {
        $this->create();
        $this->deleteJson('/api/v1/learners/'.$this->learner()->id)->assertNoContent();
        $this->postJson('/api/v1/learners', $this->payload(['cnie' => 'ZZ900002']))
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Cet e-mail est déjà utilisé par un autre compte.');
    }

    // ---------------------------------------------------------------- Modification

    public function test_modification_referentiel_inactif_conserve_nouveau_actif_exige_journal_des_champs_modifies(): void
    {
        $this->create();
        $learner = $this->learner();
        $this->promotion->forceFill(['active' => false])->save();
        $other = Promotion::query()->create(['label' => 'Autre promotion', 'active' => false]);

        // Autre champ modifié : la promotion inactive reste.
        $this->putJson("/api/v1/learners/{$learner->id}", ['phone' => '0611111111', 'promotion_id' => $this->promotion->id])
            ->assertOk()->assertJsonPath('data.promotion.active', false)->assertJsonPath('data.phone', '0611111111');
        // Nouvelle promotion inactive : refusée.
        $this->putJson("/api/v1/learners/{$learner->id}", ['promotion_id' => $other->id])->assertStatus(422)->assertJsonValidationErrors('promotion_id');

        $active = Promotion::query()->create(['label' => 'Promotion active']);
        $this->putJson("/api/v1/learners/{$learner->id}", ['promotion_id' => $active->id])->assertOk();
        $log = AuditLog::query()->where('action', AuditAction::LearnerUpdated)->latest('id')->firstOrFail();
        $this->assertSame(['promotion' => 'Promotion Fictive'], $log->old_values);
        $this->assertSame(['promotion' => 'Promotion active'], $log->new_values);
    }

    public function test_changement_d_email_annule_les_liens_meme_email_les_garde(): void
    {
        $this->create();
        $learner = $this->learner();
        $tokens = app(PasswordTokenService::class);
        $tokens->issue($learner->user, PasswordTokenType::Reset);

        $this->putJson("/api/v1/learners/{$learner->id}", ['email' => 'prenom.fictif@example.test', 'first_name' => 'Prénom2'])->assertOk();
        $this->assertSame(1, DB::table('password_tokens')->where('user_id', $learner->user_id)->count());

        $this->putJson("/api/v1/learners/{$learner->id}", ['email' => 'corrige@example.test'])->assertOk();
        $this->assertSame(0, DB::table('password_tokens')->where('user_id', $learner->user_id)->count());
    }

    // ---------------------------------------------------------------- Réinitialisation, désactivation, suppression

    public function test_reinitialisation_ancien_refuse_sessions_fermees_e_mail_et_refus_si_desactive(): void
    {
        $created = $this->create();
        $learner = $this->learner();
        DB::table('sessions')->insert(['id' => 'session-a', 'user_id' => $learner->user_id, 'payload' => '', 'last_activity' => time()]);

        $new = (string) $this->postJson("/api/v1/learners/{$learner->id}/reset-password")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('password');
        $user = $learner->user->fresh();
        $this->assertFalse(Hash::check((string) $created['password'], (string) $user?->password));
        $this->assertTrue(Hash::check($new, (string) $user?->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $learner->user_id)->count());
        Notification::assertSentTo($user, GeneratedPasswordNotification::class, fn (GeneratedPasswordNotification $n) => $n->reason === GeneratedPasswordNotification::REASON_RESET);

        $this->postJson("/api/v1/learners/{$learner->id}/deactivate")->assertOk()->assertJsonPath('data.status', 'desactive');
        $this->postJson("/api/v1/learners/{$learner->id}/reset-password")->assertStatus(422)->assertJsonPath('code', 'not_resettable');
    }

    public function test_suppression_sessions_liens_et_restauration_garde_la_desactivation(): void
    {
        $this->create();
        $learner = $this->learner();
        $learner->competences()->attach(Competence::query()->create(['label' => 'SQL'])->id);
        $this->actingAs($this->admin, 'web')->postJson("/api/v1/learners/{$learner->id}/deactivate")->assertOk();
        DB::table('sessions')->insert(['id' => 'session-b', 'user_id' => $learner->user_id, 'payload' => '', 'last_activity' => time()]);
        app(PasswordTokenService::class)->issue($learner->user, PasswordTokenType::Reset);

        $this->deleteJson("/api/v1/learners/{$learner->id}")->assertNoContent();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $learner->user_id)->count());
        $this->assertSame(0, DB::table('password_tokens')->where('user_id', $learner->user_id)->count());
        $this->assertSame(1, $learner->competences()->count());
        $this->postJson('/api/v1/learners/search', [])->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/learners/search', ['status' => 'supprime'])->assertJsonCount(1, 'data');

        $this->as(Role::Cme);
        $this->getJson("/api/v1/learners/{$learner->id}")->assertNotFound();
        $this->postJson('/api/v1/learners/search', ['status' => 'supprime'])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'web')->postJson("/api/v1/learners/{$learner->id}/restore")
            ->assertOk()->assertJsonPath('data.status', 'desactive');
    }

    // ---------------------------------------------------------------- Compétences

    public function test_competences_nouvelle_active_exigee_inactive_conservee(): void
    {
        $this->create();
        $learner = $this->learner();
        $sql = Competence::query()->create(['label' => 'SQL']);
        $cobol = Competence::query()->create(['label' => 'Cobol']);
        $this->as(Role::Cme);

        $this->putJson("/api/v1/learners/{$learner->id}/competences", ['competence_ids' => [$sql->id, $cobol->id]])->assertOk();
        $cobol->forceFill(['active' => false])->save();
        $this->putJson("/api/v1/learners/{$learner->id}/competences", ['competence_ids' => [$sql->id, $cobol->id]])
            ->assertOk()->assertJsonPath('data.competences.0.label', 'Cobol')->assertJsonPath('data.competences.0.active', false);
        $this->putJson("/api/v1/learners/{$learner->id}/competences", ['competence_ids' => [$sql->id]])->assertOk();
        $this->putJson("/api/v1/learners/{$learner->id}/competences", ['competence_ids' => [$sql->id, $cobol->id]])
            ->assertStatus(422)->assertJsonValidationErrors('competence_ids');
        $log = AuditLog::query()->where('action', AuditAction::LearnerCompetencesUpdated)->latest('id')->firstOrFail();
        $this->assertSame(['competences' => ['Cobol', 'SQL']], $log->old_values);
        $this->assertSame(['competences' => ['SQL']], $log->new_values);
    }

    // ---------------------------------------------------------------- Actions en masse

    /** @return array{0: int, 1: int, 2: int} actif, désactivé, supprimé */
    private function three(): array
    {
        $this->create();
        $this->create(['email' => 'b@example.test', 'cnie' => 'ZZ900002']);
        $this->create(['email' => 'c@example.test', 'cnie' => 'ZZ900003']);
        $b = $this->learner('b@example.test');
        $c = $this->learner('c@example.test');
        $this->postJson("/api/v1/learners/{$b->id}/deactivate")->assertOk();
        $this->deleteJson("/api/v1/learners/{$c->id}")->assertNoContent();

        return [$this->learner()->id, $b->id, $c->id];
    }

    public function test_comptage_selection_et_eligibles(): void
    {
        [$a, $b, $c] = $this->three();

        $this->postJson('/api/v1/learners/selection/count', ['ids' => [$a, $b, $c], 'action' => 'deactivate'])
            ->assertOk()->assertJson(['data' => ['selected' => 3, 'eligible' => 1]]);
        $sql = Competence::query()->create(['label' => 'SQL']);
        Learner::query()->findOrFail($a)->competences()->attach($sql->id);
        $this->postJson('/api/v1/learners/selection/count', ['ids' => [$a, $b, $c], 'action' => 'add_competence', 'competence_id' => $sql->id])
            ->assertOk()->assertJson(['data' => ['selected' => 3, 'eligible' => 1]]);
        $this->postJson('/api/v1/learners/selection/count', ['filters' => ['status' => 'desactive'], 'action' => 'deactivate'])
            ->assertOk()->assertJson(['data' => ['selected' => 1, 'eligible' => 0]]);

        // La CME : pas de comptage de désactivation ; un supprimé lui est invisible (422).
        $this->as(Role::Cme);
        $this->postJson('/api/v1/learners/selection/count', ['ids' => [$a], 'action' => 'deactivate'])->assertForbidden();
        $this->postJson('/api/v1/learners/selection/count', ['ids' => [$a, $c], 'action' => 'add_competence', 'competence_id' => $sql->id])
            ->assertStatus(422)->assertJsonValidationErrors('ids');
    }

    public function test_desactivation_en_masse_recomptage_409_puis_succes(): void
    {
        [$a, $b] = $this->three();
        $this->create(['email' => 'd@example.test', 'cnie' => 'ZZ900004']);
        $d = $this->learner('d@example.test');
        DB::table('sessions')->insert(['id' => 'session-d', 'user_id' => $d->user_id, 'payload' => '', 'last_activity' => time()]);
        $logs = AuditLog::query()->count();
        $lastId = (int) AuditLog::query()->max('id');

        // Le nombre confirmé ne correspond plus : rien n'est écrit, ni journalisé.
        $this->postJson('/api/v1/learners/bulk-deactivate', ['filters' => ['status' => 'actif'], 'expected_eligible' => 1])
            ->assertStatus(409)->assertJson(['code' => 'selection_changed', 'eligible' => 2]);
        $this->assertSame($logs, AuditLog::query()->count());
        $this->assertNull($d->user->fresh()?->deactivated_at);

        $this->postJson('/api/v1/learners/bulk-deactivate', ['filters' => ['status' => 'actif'], 'expected_eligible' => 2, 'action' => 'add_competence'])
            ->assertOk()->assertJson(['data' => ['changed' => 2]]);
        $this->assertNotNull($d->user->fresh()?->deactivated_at);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $d->user_id)->count());
        $this->assertSame(2, AuditLog::query()->where('action', AuditAction::AccountDeactivated)->where('id', '>', $lastId)->count());
        $summary = AuditLog::query()->where('action', AuditAction::LearnerBulkDeactivated)->sole();
        $this->assertSame(2, $summary->new_values['nombre']);
        $this->assertSame(['mode' => 'filtre', 'statut' => 'Actifs'], $summary->new_values['selection']);

        // 0 attendu, 0 trouvé : réponse normale, aucune écriture ; attendu non nul mais 0 trouvé : 409.
        $count = AuditLog::query()->count();
        $this->postJson('/api/v1/learners/bulk-deactivate', ['ids' => [$a, $b], 'expected_eligible' => 0])->assertOk()->assertJson(['data' => ['changed' => 0]]);
        $this->postJson('/api/v1/learners/bulk-deactivate', ['ids' => [$a, $b], 'expected_eligible' => 2])->assertStatus(409);
        $this->assertSame($count, AuditLog::query()->count());
    }

    public function test_selection_mal_formee_refusee(): void
    {
        [$a] = $this->three();
        $this->postJson('/api/v1/learners/bulk-deactivate', ['ids' => [$a], 'filters' => [], 'expected_eligible' => 1])->assertStatus(422);
        $this->postJson('/api/v1/learners/bulk-deactivate', ['ids' => [], 'expected_eligible' => 0])->assertStatus(422);
        $this->postJson('/api/v1/learners/bulk-deactivate', ['ids' => [$a, $a], 'expected_eligible' => 1])->assertStatus(422);
        $this->postJson('/api/v1/learners/bulk-deactivate', ['ids' => [99999], 'expected_eligible' => 1])->assertStatus(422);
        $this->postJson('/api/v1/learners/bulk-deactivate', ['ids' => ['x'], 'expected_eligible' => 1])->assertStatus(422);
        $this->postJson('/api/v1/learners/bulk-deactivate', ['ids' => [$a]])->assertStatus(422);
    }

    public function test_echec_du_journal_au_milieu_rien_n_est_modifie(): void
    {
        $this->create();
        $this->create(['email' => 'b@example.test', 'cnie' => 'ZZ900002']);
        $sql = Competence::query()->create(['label' => 'SQL']);
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            /** @var array<string, int> */
            private array $calls = [];

            /**
             * @param  array<string, mixed>  $old
             * @param  array<string, mixed>  $new
             */
            public function record(AuditAction $action, ?Model $subject = null, array $old = [], array $new = [], ?User $actor = null, bool $visitor = false): AuditLog
            {
                $this->calls[$action->value] = ($this->calls[$action->value] ?? 0) + 1;
                if (in_array($action, [AuditAction::AccountDeactivated, AuditAction::LearnerCompetencesUpdated], true) && $this->calls[$action->value] === 2) {
                    throw new RuntimeException('journal indisponible');
                }

                return parent::record($action, $subject, $old, $new, $actor, $visitor);
            }
        });
        DB::table('sessions')->insert(['id' => 'session-x', 'user_id' => $this->learner()->user_id, 'payload' => '', 'last_activity' => time()]);

        $this->postJson('/api/v1/learners/bulk-deactivate', ['filters' => [], 'expected_eligible' => 2])->assertStatus(500);
        $this->assertSame(0, User::query()->whereNotNull('deactivated_at')->count());
        $this->assertSame(1, DB::table('sessions')->where('id', 'session-x')->count());

        $this->postJson('/api/v1/learners/bulk-competences', ['filters' => [], 'expected_eligible' => 2, 'competence_id' => $sql->id])->assertStatus(500);
        $this->assertSame(0, DB::table('learner_competence')->count());
    }

    public function test_ajout_de_competence_en_masse_actifs_et_desactives_idempotent(): void
    {
        [$a, $b] = $this->three();
        $sql = Competence::query()->create(['label' => 'SQL']);
        $this->as(Role::Cme);

        $this->postJson('/api/v1/learners/bulk-competences', ['ids' => [$a, $b], 'expected_eligible' => 2, 'competence_id' => $sql->id])
            ->assertOk()->assertJson(['data' => ['changed' => 2]]);
        $this->postJson('/api/v1/learners/bulk-competences', ['ids' => [$a, $b], 'expected_eligible' => 0, 'competence_id' => $sql->id])
            ->assertOk()->assertJson(['data' => ['changed' => 0]]);
        $this->assertSame(2, DB::table('learner_competence')->count());

        $sql->forceFill(['active' => false])->save();
        $this->postJson('/api/v1/learners/bulk-competences', ['ids' => [$a], 'expected_eligible' => 0, 'competence_id' => $sql->id])
            ->assertStatus(422)->assertJsonValidationErrors('competence_id');
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::LearnerBulkCompetenceAdded)->count());
    }

    /** Revue Codex T-12, constat 1 : sans `ids` ni `filters`, rien n'est compté ni modifié. */
    public function test_requete_sans_selection_refusee_sans_effet(): void
    {
        $this->three();
        $sql = Competence::query()->create(['label' => 'SQL']);
        $logs = AuditLog::query()->count();

        $this->postJson('/api/v1/learners/selection/count', ['action' => 'deactivate'])->assertStatus(422)->assertJsonValidationErrors('ids');
        $this->postJson('/api/v1/learners/bulk-deactivate', ['expected_eligible' => 1])->assertStatus(422)->assertJsonValidationErrors('ids');
        $this->postJson('/api/v1/learners/bulk-competences', ['expected_eligible' => 1, 'competence_id' => $sql->id])->assertStatus(422)->assertJsonValidationErrors('ids');

        $this->assertSame(1, User::query()->whereNotNull('deactivated_at')->count());
        $this->assertSame(0, DB::table('learner_competence')->count());
        $this->assertSame($logs, AuditLog::query()->count());

        // `filters: {}` reste la demande explicite de tous les apprenants visibles.
        $this->postJson('/api/v1/learners/selection/count', ['filters' => [], 'action' => 'deactivate'])
            ->assertOk()->assertJson(['data' => ['selected' => 2, 'eligible' => 1]]);
    }

    /**
     * Revue Codex T-12, constat 2 : un compte supprimé après la sélection et avant le contrôle final
     * n'est plus concerné ; le nombre confirmé est comparé à l'état relu sous les verrous (409).
     */
    /**
     * Contre-revue T-12, constat 6 : la compétence ajoutée entre la lecture des candidats et les
     * verrous est vue par la relecture verrouillée du pivot → 409, pas d'erreur d'unicité (500).
     * Simulation sur la même connexion : le vrai scénario à deux connexions a été rejoué par Codex.
     */
    public function test_ajout_de_competence_deja_ajoutee_entre_temps(): void
    {
        $this->create();
        $learner = $this->learner();
        $sql = Competence::query()->create(['label' => 'SQL']);
        $this->actingAs($this->admin, 'web');

        $done = false;
        $afterCompetence = false;
        DB::listen(function ($query) use (&$done, &$afterCompetence, $learner, $sql): void {
            if (str_contains($query->sql, 'lock in share mode')) {
                $afterCompetence = true;
            } elseif ($afterCompetence && ! $done && str_starts_with($query->sql, 'select `learners`.* from `learners` inner join `users`')) {
                $done = true;
                DB::table('learner_competence')->insert(['learner_id' => $learner->id, 'competence_id' => $sql->id, 'created_at' => now()]);
            }
        });

        $this->postJson('/api/v1/learners/bulk-competences', ['ids' => [$learner->id], 'expected_eligible' => 1, 'competence_id' => $sql->id])
            ->assertStatus(409)->assertJson(['code' => 'selection_changed', 'eligible' => 0]);
        $this->assertTrue($done);
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::LearnerBulkCompetenceAdded)->count());
    }

    /** Contre-revue T-12, constat 7 : une ancienne ligne « CNIE consultée » reste lisible dans le journal. */
    public function test_ancienne_consultation_cnie_lisible_dans_le_journal(): void
    {
        $this->create();
        DB::table('audit_logs')->insert([
            'user_id' => $this->admin->id,
            'actor_label' => 'Admin Fictif',
            'action' => 'learner.cnie_viewed',
            'category' => 'cnie',
            'subject_type' => 'learner',
            'subject_id' => $this->learner()->id,
            'subject_label' => 'Apprenant : Prénom Fictif',
            'created_at' => now(),
        ]);

        $this->actingAs($this->admin, 'web')->getJson('/api/v1/audit-logs')->assertOk()
            ->assertJsonFragment(['action' => 'learner.cnie_viewed', 'action_label' => 'CNIE consultée (ancienne version)']);
        // Plus aucune nouvelle consultation n'est écrite.
        $this->getJson('/api/v1/learners/'.$this->learner()->id)->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::LearnerCnieViewed)->count());
    }

    public function test_ajout_de_competence_compte_supprime_entre_temps(): void
    {
        $this->create();
        $learner = $this->learner();
        $sql = Competence::query()->create(['label' => 'SQL']);
        $this->actingAs($this->admin, 'web');

        // Suppression « concurrente » juste après la lecture des candidats (première lecture des
        // fiches après le verrou de la compétence), avant le verrou des comptes.
        $done = false;
        $afterCompetence = false;
        DB::listen(function ($query) use (&$done, &$afterCompetence, $learner): void {
            if (str_contains($query->sql, 'lock in share mode')) {
                $afterCompetence = true;
            } elseif ($afterCompetence && ! $done && str_starts_with($query->sql, 'select `learners`.* from `learners` inner join `users`')) {
                $done = true;
                DB::table('users')->where('id', $learner->user_id)->update(['deleted_at' => now()]);
            }
        });

        $this->postJson('/api/v1/learners/bulk-competences', ['ids' => [$learner->id], 'expected_eligible' => 1, 'competence_id' => $sql->id])
            ->assertStatus(409)->assertJson(['code' => 'selection_changed', 'eligible' => 0]);
        $this->assertTrue($done);
        $this->assertSame(0, DB::table('learner_competence')->count());
    }

    public function test_limite_des_actions_en_masse(): void
    {
        $this->assertSame(2000, LearnerService::BULK_LIMIT);
        $this->actingAs($this->admin, 'web')
            ->postJson('/api/v1/learners/bulk-deactivate', ['ids' => range(1, 2001), 'expected_eligible' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('ids');
    }

    // ---------------------------------------------------------------- Classes (contrat T-02)

    public function test_changement_de_filiere_d_une_classe_utilisee_compte_toutes_les_fiches(): void
    {
        [, , $c] = $this->three();
        $js = Filiere::query()->create(['code' => 'JS', 'label' => 'JavaScript']);
        $url = "/api/v1/referentials/classes/{$this->class->id}";

        // Actif, désactivé et supprimé : 3 fiches.
        $this->patchJson($url, ['filiere_id' => $js->id])->assertStatus(409)
            ->assertJsonPath('code', 'class_in_use')->assertJsonPath('learners_count', 3);
        $this->patchJson($url, ['filiere_id' => $js->id, 'confirmer_changement_filiere' => true])->assertOk();
        $this->postJson('/api/v1/learners/search', [])->assertJsonPath('data.0.filiere.label', 'JS – JavaScript');
        $this->assertNotNull(Learner::query()->find($c));
    }

    /**
     * Ordre de verrouillage (contrat T-02, plan §3) : la classe est verrouillée AVANT toute écriture
     * du compte, à la création comme au changement de classe (ancienne et nouvelle, par id croissant).
     */
    public function test_ordre_de_verrouillage_classe_puis_compte(): void
    {
        $other = SchoolClass::query()->create(['name' => 'Classe B', 'filiere_id' => $this->java->id]);
        $service = app(LearnerService::class);

        DB::enableQueryLog();
        $service->create($this->payload(), $this->admin);
        $queries = array_column(DB::getQueryLog(), 'query');
        $classLock = $this->firstIndex($queries, fn (string $q) => str_contains($q, '`classes`') && str_contains($q, 'for update'));
        $userInsert = $this->firstIndex($queries, fn (string $q) => str_starts_with($q, 'insert into `users`'));
        $this->assertNotNull($classLock);
        $this->assertLessThan($userInsert, $classLock);

        DB::flushQueryLog();
        $service->update($this->learner(), ['class_id' => $other->id], $this->admin);
        $queries = array_column(DB::getQueryLog(), 'query');
        $classLock = $this->firstIndex($queries, fn (string $q) => str_contains($q, '`classes`') && str_contains($q, 'for update'));
        $userLock = $this->firstIndex($queries, fn (string $q) => str_contains($q, 'from `users`') && str_contains($q, 'for update'));
        $this->assertNotNull($classLock);
        $this->assertLessThan($userLock, $classLock);
        $this->assertStringContainsString('order by `id` asc', $queries[$classLock]);
        DB::disableQueryLog();

        $this->assertSame($other->id, $this->learner()->class_id);
    }

    /**
     * @param  list<string>  $queries
     * @param  callable(string): bool  $match
     */
    private function firstIndex(array $queries, callable $match): ?int
    {
        foreach ($queries as $i => $query) {
            if ($match($query)) {
                return $i;
            }
        }

        return null;
    }

    public function test_filtres_historiques_et_par_filiere(): void
    {
        $this->create();
        $this->promotion->forceFill(['active' => false])->save();
        $this->actingAs($this->admin, 'web');
        $this->postJson('/api/v1/learners/search', ['promotion_id' => $this->promotion->id])->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/learners/search', ['filiere_id' => $this->java->id])->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/learners/search', ['filiere_id' => 99999])->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/learners/search', ['search' => 'Prénom Fictif'])->assertJsonCount(1, 'data');
    }
}
