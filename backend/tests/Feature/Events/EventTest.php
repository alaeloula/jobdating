<?php

namespace Tests\Feature\Events;

use App\Contracts\EventUsage;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventCompany;
use App\Models\EventLearner;
use App\Models\Learner;
use App\Models\Referentials\Campus;
use App\Models\Referentials\Filiere;
use App\Models\Referentials\Promotion;
use App\Models\Referentials\SchoolClass;
use App\Models\Referentials\Sector;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Événements, participants et emplacements (ADM-05, ADM-07, ENT-01, APP-00, plan T-14 v1.4).
 * Données fictives uniquement.
 */
class EventTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-23 12:00:00', Event::TIMEZONE));
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Notification::fake();
        $this->admin = User::factory()->withRole(Role::Administrateur)->create();
        $this->withHeader('Origin', 'http://localhost');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $extra */
    private function event(string $date = '2026-12-15', array $extra = []): Event
    {
        return Event::query()->forceCreate([
            'name' => 'JobDating',
            'school_year' => Event::schoolYearFor($date),
            'date' => $date,
            'location' => 'Campus Fictif',
        ] + $extra);
    }

    private function learner(bool $active = true): Learner
    {
        $n = ++$this->sequence;
        $class = SchoolClass::query()->first() ?? SchoolClass::query()->create([
            'name' => 'CLASSE-FICTIVE',
            'filiere_id' => Filiere::query()->create(['code' => 'FIC', 'label' => 'Fictive'])->id,
        ]);
        $campus = Campus::query()->first() ?? Campus::query()->create(['name' => 'Campus Fictif']);
        $promotion = Promotion::query()->first() ?? Promotion::query()->create(['label' => 'Promotion Fictive']);
        $user = User::factory()->withRole(Role::Apprenant)->create([
            'email' => "apprenant{$n}@example.test",
            'deactivated_at' => $active ? null : now(),
        ]);

        return Learner::query()->forceCreate([
            'user_id' => $user->id,
            'cnie' => 'ZZ'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'campus_id' => $campus->id,
            'promotion_id' => $promotion->id,
            'class_id' => $class->id,
        ]);
    }

    private function company(string $name = 'Entreprise Fictive', bool $active = true): Company
    {
        $n = ++$this->sequence;
        $user = User::factory()->withRole(Role::Entreprise)->create([
            'email' => null,
            'username' => "entreprise-{$n}",
            'deactivated_at' => $active ? null : now(),
        ]);

        return Company::query()->forceCreate([
            'user_id' => $user->id,
            'name' => $name,
            'credentials_version' => 1,
            'contact_name' => 'Contact Sentinelle',
            'contact_email' => 'contact.sentinelle@example.test',
        ]);
    }

    private function join(Event $event, Company $company, ?string $floor = null, ?string $table = null): EventCompany
    {
        return EventCompany::query()->create(['event_id' => $event->id, 'company_id' => $company->id, 'floor' => $floor, 'table_label' => $table]);
    }

    /** PNG minimal valide (l'extension GD n'est pas installée). */
    private function logo(): UploadedFile
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return UploadedFile::fake()->createWithContent('logo.png', "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', 100, 100, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress("\x00\x00\x00\x00"))
            .$chunk('IEND', ''));
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->admin, 'web');
    }

    private function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    /** Passages simulés : clés « e{event}:c{company} », « e{event}:l{learner} », « e{event} ». */
    private function fakeUsage(array $counts): void
    {
        $this->app->instance(EventUsage::class, new class($counts) implements EventUsage
        {
            /** @param array<string, int> $counts */
            public function __construct(private array $counts) {}

            public function forEvent(Event $event): int
            {
                return $this->counts["e{$event->id}"] ?? 0;
            }

            public function forCompany(Event $event, Company $company): int
            {
                return $this->counts["e{$event->id}:c{$company->id}"] ?? 0;
            }

            public function forLearner(Event $event, Learner $learner): int
            {
                return $this->counts["e{$event->id}:l{$learner->id}"] ?? 0;
            }

            public function forCompanies(Event $event, array $companyIds): array
            {
                $out = [];
                foreach ($companyIds as $id) {
                    $out[$id] = $this->counts["e{$event->id}:c{$id}"] ?? 0;
                }

                return $out;
            }
        });
    }

    // ---------------------------------------------------------------- Année scolaire et statut

    /** @return iterable<string, array{string, string}> */
    public static function schoolYears(): iterable
    {
        yield '31 août 2026' => ['2026-08-31', '2025/2026'];
        yield '1er septembre 2026' => ['2026-09-01', '2026/2027'];
        yield '31 août 2027' => ['2027-08-31', '2026/2027'];
        yield '1er septembre 2027' => ['2027-09-01', '2027/2028'];
    }

    #[DataProvider('schoolYears')]
    public function test_annee_scolaire(string $date, string $year): void
    {
        $this->assertSame($year, Event::schoolYearFor($date));
    }

    public function test_statut_calcule_heure_du_maroc_autour_de_minuit(): void
    {
        $event = $this->event('2026-12-15');

        Carbon::setTestNow(Carbon::parse('2026-12-14 23:59:59', Event::TIMEZONE));
        $this->assertSame(Event::UPCOMING, $event->status());
        Carbon::setTestNow(Carbon::parse('2026-12-15 00:00:00', Event::TIMEZONE));
        $this->assertSame(Event::ONGOING, $event->status());
        Carbon::setTestNow(Carbon::parse('2026-12-15 23:59:59', Event::TIMEZONE));
        $this->assertSame(Event::ONGOING, $event->status());
        Carbon::setTestNow(Carbon::parse('2026-12-16 00:00:00', Event::TIMEZONE));
        $this->assertSame(Event::FINISHED, $event->status());
    }

    // ---------------------------------------------------------------- Création et modification

    public function test_creation_ajoute_l_annee_et_affiche_le_rappel(): void
    {
        $response = $this->asAdmin()->postJson('/api/v1/events', [
            'name' => 'JobDating', 'date' => '2026-12-15', 'location' => 'Campus Fictif',
        ])->assertCreated();

        $this->assertSame('JobDating 2026/2027', $response->json('data.full_name'));
        $this->assertSame('a_venir', $response->json('data.status'));
        $this->assertStringContainsString('Pensez à désactiver', (string) $response->json('reminder'));
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::EventCreated->value)->count());
    }

    public function test_nom_finissant_par_une_annee_refuse(): void
    {
        foreach (['JobDating 2026/2027', 'JobDating 2027', 'JobDating 2026-27'] as $name) {
            $this->asAdmin()->postJson('/api/v1/events', ['name' => $name, 'date' => '2026-12-15', 'location' => 'L'])
                ->assertStatus(422)->assertJsonValidationErrors('name');
        }
        $this->assertSame(0, Event::query()->count());
    }

    public function test_date_anterieure_au_jour_de_creation_refusee(): void
    {
        $this->asAdmin()->postJson('/api/v1/events', ['name' => 'JobDating', 'date' => '2026-11-22', 'location' => 'L'])
            ->assertStatus(422)->assertJsonValidationErrors('date');
        $this->asAdmin()->postJson('/api/v1/events', ['name' => 'JobDating', 'date' => '2026-11-23', 'location' => 'L'])
            ->assertCreated();

        // En modification, la borne reste le jour de CRÉATION, pas le jour de la modification.
        $event = Event::query()->firstOrFail();
        Carbon::setTestNow(Carbon::parse('2026-11-25 12:00:00', Event::TIMEZONE));
        $this->asAdmin()->patchJson("/api/v1/events/{$event->id}", ['date' => '2026-11-24'])->assertOk();
        $this->asAdmin()->patchJson("/api/v1/events/{$event->id}", ['date' => '2026-11-22'])
            ->assertStatus(422)->assertJsonValidationErrors('date');
    }

    public function test_un_seul_evenement_par_annee_scolaire_archives_compris(): void
    {
        $this->event('2026-12-15', ['archived_at' => now()]);

        $this->asAdmin()->postJson('/api/v1/events', ['name' => 'Autre', 'date' => '2027-03-01', 'location' => 'L'])
            ->assertStatus(422)->assertJsonValidationErrors('date');

        $next = $this->event('2027-12-15');
        $this->asAdmin()->patchJson("/api/v1/events/{$next->id}", ['date' => '2027-01-10'])
            ->assertStatus(422)->assertJsonValidationErrors('date');
        $this->assertSame('2027/2028', $next->refresh()->school_year);
    }

    public function test_changement_de_date_recalcule_l_annee(): void
    {
        $event = $this->event('2026-12-15');
        $this->asAdmin()->patchJson("/api/v1/events/{$event->id}", ['date' => '2027-09-15'])
            ->assertOk()->assertJsonPath('data.full_name', 'JobDating 2027/2028');
    }

    public function test_date_non_modifiable_avec_un_passage(): void
    {
        $event = $this->event();
        $this->fakeUsage(["e{$event->id}" => 1]);

        $this->asAdmin()->patchJson("/api/v1/events/{$event->id}", ['date' => '2026-12-20'])
            ->assertStatus(422)->assertJsonValidationErrors('date');
        $this->asAdmin()->patchJson("/api/v1/events/{$event->id}", ['location' => 'Nouveau lieu'])->assertOk();
    }

    public function test_coordonnees_ensemble_ou_pas_du_tout(): void
    {
        $this->asAdmin()->postJson('/api/v1/events', ['name' => 'JobDating', 'date' => '2026-12-15', 'location' => 'L', 'latitude' => 33.5])
            ->assertStatus(422)->assertJsonValidationErrors('longitude');
    }

    public function test_modification_partielle_garde_les_coordonnees_ensemble(): void
    {
        $event = $this->event('2026-12-15', ['latitude' => 33.5, 'longitude' => -7.5]);

        $this->asAdmin()->patchJson("/api/v1/events/{$event->id}", ['latitude' => null])
            ->assertStatus(422)->assertJsonValidationErrors('latitude');
        $this->asAdmin()->patchJson("/api/v1/events/{$event->id}", ['longitude' => null])
            ->assertStatus(422)->assertJsonValidationErrors('longitude');
        $this->assertNotNull($event->refresh()->latitude);
        $this->assertNotNull($event->longitude);

        $this->asAdmin()->patchJson("/api/v1/events/{$event->id}", ['latitude' => null, 'longitude' => null])->assertOk();
        $this->assertNull($event->refresh()->latitude);
        $this->assertNull($event->longitude);

        $this->asAdmin()->patchJson("/api/v1/events/{$event->id}", ['latitude' => 34.0])
            ->assertStatus(422)->assertJsonValidationErrors('longitude');
    }

    public function test_journal_du_nom_complet_au_changement_d_annee(): void
    {
        $event = $this->event('2026-12-15');

        $this->asAdmin()->patchJson("/api/v1/events/{$event->id}", ['date' => '2027-09-15'])->assertOk();
        $log = AuditLog::query()->where('action', AuditAction::EventUpdated->value)->latest('id')->firstOrFail();
        $this->assertSame('JobDating 2026/2027', $log->old_values['nom']);
        $this->assertSame('JobDating 2027/2028', $log->new_values['nom']);

        $this->asAdmin()->patchJson("/api/v1/events/{$event->id}", ['name' => 'Forum', 'date' => '2028-09-15'])->assertOk();
        $log = AuditLog::query()->where('action', AuditAction::EventUpdated->value)->latest('id')->firstOrFail();
        $this->assertSame('JobDating 2027/2028', $log->old_values['nom']);
        $this->assertSame('Forum 2028/2029', $log->new_values['nom']);
    }

    // ---------------------------------------------------------------- Événement courant

    public function test_index_unique_interdit_deux_courants(): void
    {
        $this->event('2026-12-15', ['is_current' => true]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->event('2027-12-15', ['is_current' => true]);
    }

    public function test_designation_inscrit_les_actifs_avec_rapport(): void
    {
        $active = $this->learner();
        $this->learner(active: false);
        $removed = $this->learner();
        $event = $this->event();
        EventLearner::query()->create(['event_id' => $event->id, 'learner_id' => $removed->id, 'enrolled_at' => now(), 'removed_at' => now()]);

        $response = $this->asAdmin()->postJson("/api/v1/events/{$event->id}/current")->assertOk();

        $this->assertSame(['enrolled' => 1, 'already' => 0, 'ignored_deactivated' => 1, 'ignored_removed' => 1], $response->json('report'));
        $this->assertTrue(EventLearner::query()->where('learner_id', $active->id)->whereNull('removed_at')->exists());
        $this->assertNotNull(EventLearner::query()->where('learner_id', $removed->id)->value('removed_at'));

        $log = AuditLog::query()->where('action', AuditAction::EventCurrentSet->value)->sole();
        $this->assertSame(1, $log->new_values['inscrits']);
        $this->assertStringNotContainsString('ZZ', (string) json_encode($log->new_values));
    }

    public function test_designation_repetee_sans_doublon_et_remplace_le_precedent(): void
    {
        $this->learner();
        $first = $this->event('2026-12-15');
        $second = $this->event('2027-12-15');

        $this->asAdmin()->postJson("/api/v1/events/{$first->id}/current")->assertOk();
        $this->asAdmin()->postJson("/api/v1/events/{$first->id}/current")->assertOk()
            ->assertJsonPath('report.enrolled', 0)->assertJsonPath('report.already', 1);
        $this->assertSame(1, EventLearner::query()->where('event_id', $first->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::EventCurrentSet->value)->count());

        $this->asAdmin()->postJson("/api/v1/events/{$second->id}/current")->assertOk();
        $this->assertFalse($first->refresh()->is_current);
        $this->assertTrue($second->refresh()->is_current);
        $this->assertSame(1, EventLearner::query()->where('event_id', $first->id)->count());
    }

    public function test_termine_ou_archive_ne_peut_pas_devenir_courant(): void
    {
        $finished = $this->event('2026-11-22');
        $archived = $this->event('2027-12-15', ['archived_at' => now()]);

        $this->asAdmin()->postJson("/api/v1/events/{$finished->id}/current")->assertStatus(409)->assertJsonPath('code', 'event_finished');
        $this->asAdmin()->postJson("/api/v1/events/{$archived->id}/current")->assertStatus(409)->assertJsonPath('code', 'event_archived');
    }

    public function test_courant_termine_reste_courant_et_statut_retirable(): void
    {
        $event = $this->event('2026-12-15');
        $this->asAdmin()->postJson("/api/v1/events/{$event->id}/current")->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-12-20 12:00:00', Event::TIMEZONE));
        $this->assertTrue($event->refresh()->is_current);

        $learner = $this->learner();
        EventLearner::query()->create(['event_id' => $event->id, 'learner_id' => $learner->id, 'enrolled_at' => now()]);
        $this->asAdmin()->deleteJson('/api/v1/events/current')->assertNoContent();
        $this->assertFalse($event->refresh()->is_current);
        $this->assertSame(1, EventLearner::query()->where('event_id', $event->id)->count());
    }

    // ---------------------------------------------------------------- Archivage et suppression

    public function test_archivage_reversible_et_lecture_seule(): void
    {
        $event = $this->event();
        $company = $this->company();
        $this->join($event, $company);
        $other = $this->company('Autre');

        $this->asAdmin()->postJson("/api/v1/events/{$event->id}/archive")->assertOk()->assertJsonPath('data.archived', true);

        $this->asAdmin()->patchJson("/api/v1/events/{$event->id}", ['location' => 'X'])->assertStatus(409);
        $this->asAdmin()->postJson("/api/v1/events/{$event->id}/companies", ['company_ids' => [$other->id]])->assertStatus(409);
        $this->asAdmin()->deleteJson("/api/v1/events/{$event->id}/companies/{$company->id}")->assertStatus(409);
        $this->asAdmin()->patchJson("/api/v1/events/{$event->id}/companies/{$company->id}/location", ['floor' => '1', 'table_label' => 'A'])->assertStatus(409);
        $this->asAdmin()->deleteJson("/api/v1/events/{$event->id}")->assertStatus(409)->assertJsonPath('code', 'event_archived');
        $this->assertNotNull(Event::query()->find($event->id));
        $this->assertSame(1, EventCompany::query()->where('event_id', $event->id)->count());

        $this->asAdmin()->postJson("/api/v1/events/{$event->id}/unarchive")->assertOk()->assertJsonPath('data.archived', false);
        $this->asAdmin()->patchJson("/api/v1/events/{$event->id}", ['location' => 'X'])->assertOk();
    }

    public function test_courant_ni_archivable_ni_supprimable(): void
    {
        $event = $this->event('2026-12-15', ['is_current' => true]);

        $this->asAdmin()->postJson("/api/v1/events/{$event->id}/archive")->assertStatus(409)->assertJsonPath('code', 'event_current');
        $this->asAdmin()->deleteJson("/api/v1/events/{$event->id}")->assertStatus(409)->assertJsonPath('code', 'event_current');
    }

    public function test_suppression_refusee_avec_passages_sinon_participations_supprimees(): void
    {
        $used = $this->event('2026-12-15');
        $free = $this->event('2027-12-15');
        $this->join($used, $this->company('Avec passage'));
        $this->join($free, $this->company('Sans passage'));
        $this->fakeUsage(["e{$used->id}" => 1]);

        $this->asAdmin()->deleteJson("/api/v1/events/{$used->id}")->assertStatus(409)->assertJsonPath('code', 'event_has_passages');
        $this->asAdmin()->deleteJson("/api/v1/events/{$free->id}")->assertNoContent();

        $this->assertNotNull(Event::query()->find($used->id));
        $this->assertSame([$used->id], EventCompany::query()->pluck('event_id')->all());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::EventDeleted->value)->count());
    }

    // ---------------------------------------------------------------- Inscription automatique

    public function test_apprenant_cree_a_la_main_inscrit_au_courant(): void
    {
        $seed = $this->learner();
        $event = $this->event('2026-12-15', ['is_current' => true]);

        $id = $this->asAdmin()->postJson('/api/v1/learners', [
            'cnie' => 'ZZ900001', 'first_name' => 'Fictif', 'last_name' => 'Apprenant', 'email' => 'fictif@example.test',
            'campus_id' => $seed->campus_id, 'promotion_id' => $seed->promotion_id, 'class_id' => $seed->class_id,
        ])->assertCreated()->json('data.id');

        $this->assertTrue(EventLearner::query()->where('event_id', $event->id)->where('learner_id', $id)->whereNull('removed_at')->exists());
    }

    public function test_sans_courant_aucune_inscription(): void
    {
        $seed = $this->learner();
        $this->event();

        $this->asAdmin()->postJson('/api/v1/learners', [
            'cnie' => 'ZZ900001', 'first_name' => 'Fictif', 'last_name' => 'Apprenant', 'email' => 'fictif@example.test',
            'campus_id' => $seed->campus_id, 'promotion_id' => $seed->promotion_id, 'class_id' => $seed->class_id,
        ])->assertCreated();

        $this->assertSame(0, EventLearner::query()->count());
    }

    public function test_reactivation_inscrit_mais_ne_retablit_pas_un_retrait(): void
    {
        $plain = $this->learner(active: false);
        $removed = $this->learner(active: false);
        $event = $this->event('2026-12-15', ['is_current' => true]);
        EventLearner::query()->create(['event_id' => $event->id, 'learner_id' => $removed->id, 'enrolled_at' => now(), 'removed_at' => now()]);

        $this->asAdmin()->postJson("/api/v1/learners/{$plain->id}/reactivate")->assertOk();
        $this->asAdmin()->postJson("/api/v1/learners/{$removed->id}/reactivate")->assertOk();

        $this->assertTrue(EventLearner::query()->where('learner_id', $plain->id)->whereNull('removed_at')->exists());
        $this->assertNotNull(EventLearner::query()->where('learner_id', $removed->id)->value('removed_at'));
    }

    public function test_restauration_inscrit_seulement_un_compte_actif(): void
    {
        $active = $this->learner();
        $inactive = $this->learner(active: false);
        $event = $this->event('2026-12-15', ['is_current' => true]);
        $active->user->delete();
        $inactive->user->delete();

        $this->asAdmin()->postJson("/api/v1/learners/{$active->id}/restore")->assertOk();
        $this->asAdmin()->postJson("/api/v1/learners/{$inactive->id}/restore")->assertOk();

        $this->assertTrue(EventLearner::query()->where('event_id', $event->id)->where('learner_id', $active->id)->exists());
        $this->assertFalse(EventLearner::query()->where('learner_id', $inactive->id)->exists());
    }

    public function test_participant_visible_exige_compte_actif_et_ligne_non_retiree(): void
    {
        $visible = $this->learner();
        $deactivated = $this->learner();
        $removed = $this->learner();
        $deleted = $this->learner();
        $event = $this->event();
        foreach ([$visible, $deactivated, $deleted] as $l) {
            EventLearner::query()->create(['event_id' => $event->id, 'learner_id' => $l->id, 'enrolled_at' => now()]);
        }
        EventLearner::query()->create(['event_id' => $event->id, 'learner_id' => $removed->id, 'enrolled_at' => now(), 'removed_at' => now()]);
        $deactivated->user->forceFill(['deactivated_at' => now()])->save();
        $deleted->user->delete();

        $this->assertSame([$visible->id], Learner::query()->participatingIn($event->id)->pluck('id')->all());
    }

    public function test_retrait_et_reinscription_d_un_apprenant(): void
    {
        $learner = $this->learner();
        $event = $this->event('2026-12-15', ['is_current' => true]);
        EventLearner::query()->create(['event_id' => $event->id, 'learner_id' => $learner->id, 'enrolled_at' => now()]);

        $this->asAdmin()->deleteJson("/api/v1/events/{$event->id}/learners/{$learner->id}")->assertNoContent();
        $this->assertNotNull(EventLearner::query()->where('learner_id', $learner->id)->value('removed_at'));
        $status = $this->asAdmin()->postJson('/api/v1/learners/search', [])->assertOk();
        $this->assertSame('retire', $status->json('data.0.event_status'));

        $this->asAdmin()->postJson("/api/v1/events/{$event->id}/learners/{$learner->id}/reinstate")->assertNoContent();
        $this->assertNull(EventLearner::query()->where('learner_id', $learner->id)->value('removed_at'));
    }

    public function test_retrait_d_un_participant_compte_par_evenement(): void
    {
        $learner = $this->learner();
        $company = $this->company();
        $first = $this->event('2026-12-15');
        $second = $this->event('2027-12-15');
        foreach ([$first, $second] as $event) {
            EventLearner::query()->create(['event_id' => $event->id, 'learner_id' => $learner->id, 'enrolled_at' => now()]);
            $this->join($event, $company);
        }
        $this->fakeUsage(["e{$first->id}:c{$company->id}" => 1, "e{$first->id}:l{$learner->id}" => 1]);

        $message = 'Ce participant a déjà des passages dans cet événement, il ne peut pas être retiré';
        $this->asAdmin()->deleteJson("/api/v1/events/{$first->id}/companies/{$company->id}")->assertStatus(409)->assertJsonPath('message', $message);
        $this->asAdmin()->deleteJson("/api/v1/events/{$first->id}/learners/{$learner->id}")->assertStatus(409)->assertJsonPath('message', $message);

        $this->asAdmin()->deleteJson("/api/v1/events/{$second->id}/companies/{$company->id}")->assertNoContent();
        $this->asAdmin()->deleteJson("/api/v1/events/{$second->id}/learners/{$learner->id}")->assertNoContent();
    }

    // ---------------------------------------------------------------- Entreprises

    public function test_creation_entreprise_affectee_avec_emplacement(): void
    {
        $event = $this->event();

        $response = $this->asAdmin()->postJson('/api/v1/companies', [
            'name' => 'Acme Fictive', 'username' => 'acme-fictive', 'event_id' => $event->id, 'floor' => '1', 'table_label' => 'B12',
        ])->assertCreated();

        $this->assertSame('JobDating 2026/2027', $response->json('credentials.event.name'));
        $this->assertSame('B12', $response->json('credentials.event.table_label'));
        $this->assertSame(1, EventCompany::query()->where('event_id', $event->id)->count());
    }

    public function test_creation_entreprise_sans_emplacement_reussit(): void
    {
        $event = $this->event();
        $this->asAdmin()->postJson('/api/v1/companies', ['name' => 'Acme', 'username' => 'acme', 'event_id' => $event->id])->assertCreated();
        $this->assertNull(EventCompany::query()->sole()->floor);
    }

    public function test_creation_entreprise_evenement_invalide_rien_n_est_cree(): void
    {
        $finished = $this->event('2026-11-22');
        $archived = $this->event('2027-12-15', ['archived_at' => now()]);
        $logo = fn () => $this->logo();

        foreach ([null, $finished->id, $archived->id, 999999] as $eventId) {
            $this->asAdmin()->post('/api/v1/companies', array_filter([
                'name' => 'Refusée', 'username' => 'refusee', 'event_id' => $eventId, 'logo' => $logo(),
            ]), ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('event_id');
        }

        $this->assertSame(0, Company::query()->count());
        $this->assertNull(User::query()->where('username', 'refusee')->first());
        $this->assertSame([], Storage::disk('local')->allFiles('logos'));
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::CompanyCreated->value)->count());
    }

    public function test_echec_apres_la_fiche_ne_laisse_rien(): void
    {
        $event = $this->event();
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(AuditAction $action, ?Model $subject = null, array $old = [], array $new = [], ?User $actor = null, bool $visitor = false): AuditLog
            {
                if ($action === AuditAction::EventCompanyAdded) {
                    throw new RuntimeException('Échec simulé');
                }

                return parent::record($action, $subject, $old, $new, $actor, $visitor);
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->asAdmin()->post('/api/v1/companies', [
                'name' => 'Partielle', 'username' => 'partielle', 'event_id' => $event->id,
                'logo' => $this->logo(),
            ], ['Accept' => 'application/json']);
            $this->fail('Une erreur était attendue.');
        } catch (RuntimeException $e) {
            $this->assertSame('Échec simulé', $e->getMessage());
        }

        $this->assertSame(0, Company::query()->count());
        $this->assertSame(0, EventCompany::query()->count());
        $this->assertNull(User::query()->where('username', 'partielle')->first());
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::CompanyCreated->value)->count());
        $this->assertSame([], Storage::disk('local')->allFiles('logos'));
    }

    public function test_ajout_en_masse_avec_rapport_et_une_ligne_de_journal(): void
    {
        $event = $this->event();
        $already = $this->company('Déjà');
        $this->join($event, $already);
        $new = $this->company('Nouvelle');
        $inactive = $this->company('Désactivée', active: false);

        $this->assertSame([$new->id], array_column($this->asAdmin()->getJson("/api/v1/events/{$event->id}/available-companies")->assertOk()->json('data'), 'id'));

        $this->asAdmin()->postJson("/api/v1/events/{$event->id}/companies", ['company_ids' => [$new->id, $already->id, $inactive->id]])
            ->assertOk()->assertJson(['report' => ['added' => 1, 'already' => 1, 'ignored' => 1]]);

        $this->assertSame(2, EventCompany::query()->where('event_id', $event->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::EventCompanyAdded->value)->count());
    }

    public function test_retrait_entreprise_disparait_de_l_evenement(): void
    {
        $event = $this->event();
        $company = $this->company();
        $this->join($event, $company);

        $this->asAdmin()->deleteJson("/api/v1/events/{$event->id}/companies/{$company->id}")->assertNoContent();
        $this->assertSame([], $this->asAdmin()->getJson("/api/v1/events/{$event->id}/companies")->assertOk()->json('data'));
        $this->assertNotNull(Company::query()->find($company->id));
    }

    public function test_emplacement_par_la_cme_sans_ajout_ni_retrait(): void
    {
        $event = $this->event();
        $company = $this->company();
        $other = $this->company('Autre');
        $this->join($event, $company);
        $cme = User::factory()->withRole(Role::Cme)->create();

        $this->asUser($cme)->patchJson("/api/v1/events/{$event->id}/companies/{$company->id}/location", ['floor' => '2', 'table_label' => 'C03'])
            ->assertOk()->assertJsonPath('data.table_label', 'C03');

        $row = $this->asUser($cme)->getJson("/api/v1/events/{$event->id}/companies")->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('username', $row);
        $this->assertArrayNotHasKey('credentials_printed', $row);

        $this->asUser($cme)->postJson("/api/v1/events/{$event->id}/companies", ['company_ids' => [$other->id]])->assertForbidden();
        $this->asUser($cme)->deleteJson("/api/v1/events/{$event->id}/companies/{$company->id}")->assertForbidden();
        $this->asUser($cme)->getJson("/api/v1/events/{$event->id}/companies?filter=non_imprimee")->assertForbidden();
    }

    public function test_emplacement_d_une_entreprise_non_inscrite_introuvable(): void
    {
        $event = $this->event();
        $company = $this->company();

        $this->asAdmin()->patchJson("/api/v1/events/{$event->id}/companies/{$company->id}/location", ['floor' => '1', 'table_label' => 'A'])->assertNotFound();
    }

    public function test_filtres_et_comptes_des_participantes(): void
    {
        $event = $this->event();
        $placed = $this->company('Placée');
        $this->join($event, $placed, '1', 'A');
        $missing = $this->company('Sans');
        $this->join($event, $missing);
        $placed->forceFill(['credentials_printed_at' => now()])->save();

        $json = $this->asAdmin()->getJson("/api/v1/events/{$event->id}/companies?filter=sans_emplacement")->assertOk()->json();
        $this->assertSame([$missing->id], array_column($json['data'], 'id'));
        $this->assertSame(['total' => 2, 'without_location' => 1, 'not_printed' => 1], $json['meta']);

        $printed = $this->asAdmin()->getJson("/api/v1/events/{$event->id}/companies?filter=non_imprimee")->assertOk()->json('data');
        $this->assertSame([$missing->id], array_column($printed, 'id'));
    }

    // ---------------------------------------------------------------- Vue de l'entreprise

    public function test_entreprise_voit_seulement_le_courant(): void
    {
        $company = $this->company();
        $old = $this->event('2027-12-15');
        $current = $this->event('2026-12-15', ['is_current' => true]);
        $this->join($old, $company, '9', 'ANCIEN');
        $this->join($current, $company, '1', 'B12');

        $data = $this->asUser($company->user)->getJson("/api/v1/me/company-event?event_id={$old->id}")->assertOk()->json('data');

        $this->assertSame($current->id, $data['id']);
        $this->assertSame('JobDating 2026/2027', $data['name']);
        $this->assertSame('B12', $data['table_label']);
    }

    public function test_entreprise_etats_vides(): void
    {
        $company = $this->company();
        $this->asUser($company->user)->getJson('/api/v1/me/company-event')->assertOk()->assertJsonPath('data', null);

        $future = $this->event('2027-12-15');
        $this->join($future, $company);
        $this->asUser($company->user)->getJson('/api/v1/me/company-event')->assertOk()->assertJsonPath('data', null);

        $this->event('2026-12-15', ['is_current' => true]);
        $this->asUser($company->user)->getJson('/api/v1/me/company-event')->assertOk()->assertJsonPath('data', null);
    }

    public function test_fiche_apres_reinitialisation(): void
    {
        $company = $this->company();
        $other = $this->company('Autre');
        $current = $this->event('2026-12-15', ['is_current' => true]);
        $future = $this->event('2027-12-15');
        $foreign = $this->event('2028-12-15');
        $this->join($current, $company, '1', 'B12');
        $this->join($future, $company, '2', 'C03');
        $this->join($foreign, $other);
        $password = $company->user->password;

        $this->asAdmin()->postJson("/api/v1/companies/{$company->id}/reset-password", ['event_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('event_id');
        $this->assertSame($password, $company->user->refresh()->password);
        $this->assertSame(1, $company->refresh()->credentials_version);

        $this->asAdmin()->postJson("/api/v1/companies/{$company->id}/reset-password", ['event_id' => $future->id])
            ->assertOk()->assertJsonPath('credentials.event.table_label', 'C03');
        $this->asAdmin()->postJson("/api/v1/companies/{$company->id}/reset-password")
            ->assertOk()->assertJsonPath('credentials.event.table_label', 'B12');

        $this->asAdmin()->postJson("/api/v1/companies/{$other->id}/reset-password")
            ->assertOk()->assertJsonPath('credentials.event', null);
    }

    // ---------------------------------------------------------------- Vue de l'apprenant

    public function test_ma_journee_sans_fuite(): void
    {
        $learner = $this->learner();
        $event = $this->event('2026-12-15', ['is_current' => true]);
        EventLearner::query()->create(['event_id' => $event->id, 'learner_id' => $learner->id, 'enrolled_at' => now()]);
        $sector = Sector::query()->create(['label' => 'Banque', 'active' => true]);
        $visible = $this->company('Visible');
        $visible->forceFill(['sector_id' => $sector->id])->save();
        $this->join($event, $visible, '1', 'B12');
        $this->join($event, $this->company('Masquée', active: false));

        $response = $this->asUser($learner->user)->getJson('/api/v1/me/learner-event')->assertOk();

        $this->assertSame('JobDating 2026/2027', $response->json('data.event.name'));
        $this->assertSame([[
            'id' => $visible->id, 'name' => 'Visible', 'sector' => 'Banque', 'floor' => '1', 'table_label' => 'B12',
        ]], $response->json('data.companies'));
        $raw = (string) $response->getContent();
        foreach (['Sentinelle', 'sentinelle', 'entreprise-', 'username', 'cnie', 'ZZ0'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }
    }

    public function test_apprenant_retire_ou_non_inscrit_recoit_un_etat_vide(): void
    {
        $removed = $this->learner();
        $none = $this->learner();
        $event = $this->event('2026-12-15', ['is_current' => true]);
        EventLearner::query()->create(['event_id' => $event->id, 'learner_id' => $removed->id, 'enrolled_at' => now(), 'removed_at' => now()]);

        $this->asUser($removed->user)->getJson('/api/v1/me/learner-event')->assertOk()->assertJsonPath('data', null);
        $this->asUser($none->user)->getJson('/api/v1/me/learner-event')->assertOk()->assertJsonPath('data', null);
    }

    // ---------------------------------------------------------------- Droits (5 rôles)

    /**
     * Méthode, adresse, rôles autorisés, statut attendu pour eux et corps de la requête.
     *
     * @return iterable<string, array{string, string, list<string>, int, array<string, mixed>}>
     */
    public static function routes(): iterable
    {
        $admin = ['administrateur'];
        $location = ['administrateur', 'cme'];

        yield 'liste' => ['GET', '/api/v1/events', $admin, 200, []];
        yield 'détail' => ['GET', '/api/v1/events/{event}', $admin, 200, []];
        yield 'création' => ['POST', '/api/v1/events', $admin, 201, ['name' => 'Matrice', 'date' => '2028-12-15', 'location' => 'Campus Fictif']];
        yield 'modification' => ['PATCH', '/api/v1/events/{event}', $admin, 200, ['location' => 'Autre lieu']];
        yield 'suppression' => ['DELETE', '/api/v1/events/{event}', $admin, 204, []];
        yield 'courant' => ['POST', '/api/v1/events/{event}/current', $admin, 200, []];
        yield 'retrait courant' => ['DELETE', '/api/v1/events/current', $admin, 204, []];
        yield 'archiver' => ['POST', '/api/v1/events/{event}/archive', $admin, 200, []];
        yield 'désarchiver' => ['POST', '/api/v1/events/{event}/unarchive', $admin, 200, []];
        yield 'options' => ['GET', '/api/v1/event-options', $admin, 200, []];
        yield 'résumé' => ['GET', '/api/v1/events/current/summary', $location, 200, []];
        yield 'participantes' => ['GET', '/api/v1/events/{event}/companies', $location, 200, []];
        yield 'emplacement' => ['PATCH', '/api/v1/events/{event}/companies/{company}/location', $location, 200, ['floor' => '1', 'table_label' => 'A']];
        yield 'disponibles' => ['GET', '/api/v1/events/{event}/available-companies', $admin, 200, []];
        yield 'ajout' => ['POST', '/api/v1/events/{event}/companies', $admin, 200, ['company_ids' => ['{other}']]];
        yield 'retrait entreprise' => ['DELETE', '/api/v1/events/{event}/companies/{company}', $admin, 204, []];
        yield 'retrait apprenant' => ['DELETE', '/api/v1/events/{event}/learners/{learner}', $admin, 204, []];
        yield 'réinscription' => ['POST', '/api/v1/events/{event}/learners/{learner}/reinstate', $admin, 204, []];
        yield 'vue entreprise' => ['GET', '/api/v1/me/company-event', ['entreprise'], 200, []];
        yield 'vue apprenant' => ['GET', '/api/v1/me/learner-event', ['apprenant'], 200, []];
    }

    /**
     * @param  list<string>  $allowed
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('routes')]
    public function test_matrice_des_droits(string $method, string $uri, array $allowed, int $expected, array $body): void
    {
        $event = $this->event();
        $company = $this->company();
        $other = $this->company('Disponible');
        $learner = $this->learner();
        $this->join($event, $company);
        EventLearner::query()->create(['event_id' => $event->id, 'learner_id' => $learner->id, 'enrolled_at' => now()]);
        if ($uri === '/api/v1/events/current/summary' || $uri === '/api/v1/events/current') {
            $event->forceFill(['is_current' => true])->save();
        }
        $url = str_replace(['{event}', '{company}', '{learner}'], [(string) $event->id, (string) $company->id, (string) $learner->id], $uri);
        if (isset($body['company_ids'])) {
            $body['company_ids'] = [$other->id];
        }

        $this->app['auth']->forgetGuards();
        $this->json($method, $url, $body)->assertUnauthorized();

        $accounts = [
            'administrateur' => $this->admin,
            'cme' => User::factory()->withRole(Role::Cme)->create(),
            'direction' => User::factory()->withRole(Role::Direction)->create(),
            'entreprise' => $company->user,
            'apprenant' => $learner->user,
        ];
        foreach ($accounts as $role => $user) {
            // Chaque rôle part du même état : l'action d'un rôle autorisé est annulée ensuite.
            DB::beginTransaction();
            $status = $this->asUser($user)->json($method, $url, $body)->getStatusCode();
            DB::rollBack();
            $this->assertSame(in_array($role, $allowed, true) ? $expected : 403, $status, $role);
        }
    }
}
