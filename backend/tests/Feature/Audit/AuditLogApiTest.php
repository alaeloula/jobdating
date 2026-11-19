<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Consultation du journal (TRA-01) : administrateur uniquement, filtres, pagination bornée.
 */
class AuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->withRole(Role::Administrateur)->create(['email' => 'admin@example.test']);
        $this->withHeader('Origin', 'http://localhost');
    }

    public function test_reserve_a_une_session(): void
    {
        $this->getJson('/api/v1/audit-logs')->assertUnauthorized();
    }

    /**
     * @return iterable<string, array{Role}>
     */
    public static function otherRoles(): iterable
    {
        foreach ([Role::Cme, Role::Direction, Role::Entreprise, Role::Apprenant] as $role) {
            yield $role->value => [$role];
        }
    }

    #[DataProvider('otherRoles')]
    public function test_refuse_aux_autres_roles(Role $role): void
    {
        $user = User::factory()->withRole($role)->create();

        $this->actingAs($user, 'web')->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->actingAs($user, 'web')->getJson('/api/v1/audit-logs/filters')->assertForbidden();
    }

    public function test_l_administrateur_consulte_le_journal_avec_libelles(): void
    {
        $this->actingAs($this->admin, 'web')
            ->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'created_at', 'actor', 'action', 'action_label', 'category', 'category_label', 'subject', 'old_values', 'new_values', 'ip']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('data.0.action', AuditAction::RoleChanged->value)
            ->assertJsonPath('data.0.action_label', 'Rôle modifié')
            ->assertJsonPath('data.0.category_label', 'Comptes et mots de passe');
    }

    public function test_historique_complet_d_un_element(): void
    {
        $target = User::factory()->withRole(Role::Apprenant)->create();
        $target->forceFill(['deactivated_at' => now()])->save();
        User::factory()->withRole(Role::Cme)->create();

        $response = $this->actingAs($this->admin, 'web')
            ->getJson("/api/v1/audit-logs?subject_type=user&subject_id={$target->id}")
            ->assertOk();

        $actions = collect($response->json('data'))->pluck('action')->all();
        $this->assertEqualsCanonicalizing(
            [AuditAction::AccountCreated->value, AuditAction::RoleChanged->value, AuditAction::AccountDeactivated->value],
            $actions,
        );
    }

    public function test_filtres_categorie_auteur_et_periode(): void
    {
        $other = User::factory()->withRole(Role::Cme)->create();
        $this->actingAs($other, 'web');
        app(AuditLogger::class)->record(AuditAction::Logout, $other);
        AuditLog::query()->create([
            'user_id' => $other->id, 'actor_label' => 'x', 'action' => AuditAction::Logout,
            'category' => AuditAction::Logout->category(), 'created_at' => now()->subDays(10),
        ]);

        $this->actingAs($this->admin, 'web');

        $byCategory = $this->getJson('/api/v1/audit-logs?category=connexions')->assertOk()->json('data');
        $this->assertNotEmpty($byCategory);
        $this->assertSame(['connexions'], array_values(array_unique(array_column($byCategory, 'category'))));

        $byAuthor = $this->getJson("/api/v1/audit-logs?user_id={$other->id}")->assertOk()->json('data');
        $this->assertCount(2, $byAuthor);

        $today = now()->toDateString();
        $byPeriod = $this->getJson("/api/v1/audit-logs?user_id={$other->id}&from={$today}&to={$today}")->assertOk()->json('data');
        $this->assertCount(1, $byPeriod);
    }

    public function test_pagination_bornee_et_filtres_invalides(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->getJson('/api/v1/audit-logs?per_page=101')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/audit-logs?category=inconnue')->assertStatus(422)->assertJsonValidationErrors(['category']);
        $this->getJson('/api/v1/audit-logs?from=2026-11-20&to=2026-11-10')->assertStatus(422)->assertJsonValidationErrors(['to']);
        $this->getJson('/api/v1/audit-logs?subject_type=user')->assertStatus(422)->assertJsonValidationErrors(['subject_id']);
        $this->getJson('/api/v1/audit-logs?per_page=1')->assertOk()->assertJsonPath('meta.per_page', 1);
    }

    public function test_filtres_disponibles(): void
    {
        $this->actingAs($this->admin, 'web')
            ->getJson('/api/v1/audit-logs/filters')
            ->assertOk()
            ->assertJsonPath('data.categories.0', ['value' => 'connexions', 'label' => 'Connexions'])
            ->assertJsonCount(9, 'data.categories');
    }
}
