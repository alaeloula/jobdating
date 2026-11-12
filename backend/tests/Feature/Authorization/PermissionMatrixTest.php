<?php

namespace Tests\Feature\Authorization;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Vérifie chaque cellule de la matrice des droits (CDC 3.2, EXI-MAIN-01).
 *
 * La matrice ci-dessous est recopiée du cahier des charges, indépendamment de
 * App\Enums\Role : une erreur dans le code ne peut pas se corriger toute seule ici.
 * 🔸 (périmètre limité) compte comme « autorisé » : le périmètre est testé par les policies.
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['administrateur', 'cme', 'entreprise', 'apprenant', 'direction'];

    /**
     * Ligne du CDC 3.2 => [Admin, CME, Entreprise, Apprenant, Direction].
     *
     * @var array<string, array{bool, bool, bool, bool, bool}>
     */
    private const MATRIX = [
        'users.manage' => [true, false, false, false, false],            // Gérer les comptes et les rôles
        'learners.update' => [true, false, false, false, false],         // Modifier un apprenant, réinitialiser son mot de passe
        'learners.view_cnie' => [true, false, false, false, false],      // Consulter la CNIE d'un apprenant
        'referentials.manage' => [true, true, false, false, false],      // Gérer les référentiels
        'events.manage' => [true, false, false, false, false],           // Créer / modifier un événement
        'program.manage' => [true, true, false, false, false],           // Gérer le programme de l'événement
        'participants.manage' => [true, false, false, false, false],     // Ajouter ou retirer des participants
        'locations.manage' => [true, true, false, false, false],         // Saisir l'emplacement des entreprises
        'program.view' => [true, true, true, true, true],                // Consulter le programme
        'learners.view' => [true, true, true, false, true],              // Consulter la liste des apprenants
        'cv.view' => [true, true, true, true, true],                     // Consulter et télécharger un CV validé
        'companies.view' => [true, true, true, true, true],              // Consulter les entreprises
        'passages.create' => [true, false, true, false, false],          // Enregistrer un passage
        'evaluations.update' => [true, false, true, false, false],       // Saisir / modifier une évaluation
        'passages.cancel' => [true, false, true, false, false],          // Annuler un passage
        'passages.change_learner' => [true, false, false, false, false], // Changer l'apprenant d'un passage
        'evaluations.view' => [true, true, true, false, true],           // Consulter les évaluations d'un apprenant
        'dashboard.global' => [true, true, false, false, true],          // Tableau de bord global et alertes
        'stats.companies' => [true, true, true, false, true],            // Statistiques par entreprise
        'stats.learners' => [true, true, false, false, true],            // Statistiques par apprenant
        'exports.create' => [true, true, true, false, true],             // Exporter
        'notifications.send' => [true, true, true, false, false],        // Envoyer une notification
        'cv.upload' => [true, true, false, false, false],                // Téléverser ou importer le CV d'un apprenant
        'cv.history' => [true, true, false, false, false],               // Consulter l'historique des ajouts de CV
        'audit.view' => [true, false, false, false, false],              // Consulter le journal d'audit
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function cells(): iterable
    {
        foreach (self::MATRIX as $permission => $row) {
            foreach (self::ROLES as $index => $role) {
                yield "{$role} / {$permission}" => [$role, $permission, $row[$index]];
            }
        }
    }

    #[DataProvider('cells')]
    public function test_cellule_de_la_matrice(string $role, string $permission, bool $expected): void
    {
        $user = User::factory()->create();
        $user->setRole(Role::from($role));

        $this->assertSame(
            $expected,
            $user->hasPermissionTo($permission),
            "Rôle « {$role} », permission « {$permission} » : attendu ".($expected ? 'autorisé' : 'interdit').'.',
        );
    }

    public function test_la_matrice_et_le_code_ont_les_memes_permissions(): void
    {
        $this->assertEqualsCanonicalizing(
            array_keys(self::MATRIX),
            array_map(fn (Permission $permission) => $permission->value, Permission::cases()),
        );
    }

    public function test_la_matrice_et_le_code_ont_les_memes_roles(): void
    {
        $this->assertEqualsCanonicalizing(
            self::ROLES,
            array_map(fn (Role $role) => $role->value, Role::cases()),
        );
    }
}
