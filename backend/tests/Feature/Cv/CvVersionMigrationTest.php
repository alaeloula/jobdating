<?php

namespace Tests\Feature\Cv;

use App\Enums\Role;
use App\Models\Learner;
use App\Models\Referentials\Campus;
use App\Models\Referentials\Filiere;
use App\Models\Referentials\Promotion;
use App\Models\Referentials\SchoolClass;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migration du numéro de version (plan T-21 v1.2, § 3) : sur des CV déjà présents, chaque ligne reçoit
 * une version différente, non nulle ; contenu, chemin et historique sont conservés. DatabaseMigrations :
 * une modification de schéma valide implicitement toute transaction MySQL.
 */
class CvVersionMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_versions_distinctes_pour_les_cv_existants(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $migration = require database_path('migrations/2026_11_27_100000_add_version_to_cv_documents.php');
        $migration->down();

        $class = SchoolClass::query()->create(['name' => 'CLASSE-FICTIVE', 'filiere_id' => Filiere::query()->create(['code' => 'FIC', 'label' => 'Fictive'])->id]);
        $campus = Campus::query()->create(['name' => 'Campus Fictif']);
        $promotion = Promotion::query()->create(['label' => 'Promotion Fictive']);
        foreach ([1, 2, 3] as $n) {
            $user = User::factory()->withRole(Role::Apprenant)->create(['email' => "apprenant{$n}@example.test"]);
            $learner = Learner::query()->forceCreate(['user_id' => $user->id, 'cnie' => 'ZZ00000'.$n, 'campus_id' => $campus->id, 'promotion_id' => $promotion->id, 'class_id' => $class->id]);
            DB::table('cv_documents')->insert([
                'learner_id' => $learner->id, 'source' => 'televerse', 'added_at' => now(), 'path' => "cvs/existant-{$n}.bin",
                'sha256' => str_repeat((string) $n, 64), 'size' => 100 + $n, 'mime' => 'application/pdf', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('cv_history')->insert(['learner_id' => $learner->id, 'action' => 'televerse', 'source' => 'televerse', 'created_at' => now()]);

        $migration->up();

        $rows = DB::table('cv_documents')->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $versions = $rows->pluck('version')->all();
        $this->assertNotContains(null, $versions);
        $this->assertCount(3, array_unique($versions));
        $this->assertSame(['cvs/existant-1.bin', 'cvs/existant-2.bin', 'cvs/existant-3.bin'], $rows->pluck('path')->all());
        $this->assertSame(1, DB::table('cv_history')->count());

        // Contraintes (revue Codex T-21, suggestion 5) : version obligatoire et unique.
        try {
            DB::table('cv_documents')->where('id', $rows[1]->id)->update(['version' => $rows[0]->version]);
            $this->fail('Doublon de version accepté.');
        } catch (UniqueConstraintViolationException) {
            // attendu
        }
        try {
            DB::table('cv_documents')->where('id', $rows[1]->id)->update(['version' => null]);
            $this->fail('Version nulle acceptée.');
        } catch (QueryException) {
            // attendu
        }

        // Retour arrière sur des lignes remplies : colonne retirée, lignes conservées ; puis de nouveau.
        $migration->down();
        $this->assertFalse(Schema::hasColumn('cv_documents', 'version'));
        $this->assertSame(3, DB::table('cv_documents')->count());
        $migration->up();
        $this->assertSame(3, DB::table('cv_documents')->distinct()->count('version'));
    }
}
