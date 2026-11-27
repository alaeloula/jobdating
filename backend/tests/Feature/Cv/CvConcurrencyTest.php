<?php

namespace Tests\Feature\Cv;

use App\Enums\Role;
use App\Models\CvDocument;
use App\Models\CvHistoryEntry;
use App\Models\Learner;
use App\Models\Referentials\Campus;
use App\Models\Referentials\Filiere;
use App\Models\Referentials\Promotion;
use App\Models\Referentials\SchoolClass;
use App\Models\User;
use App\Services\Cv\CvRuleException;
use App\Services\Cv\CvService;
use App\Services\Cv\CvStorage;
use App\Services\Learners\LearnerService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDOException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use RuntimeException;
use Tests\Concerns\ForksDatabaseConnections;
use Tests\Support\FakePdf;
use Tests\TestCase;

/**
 * Concurrence des CV (plan T-20 v1.2, § 5 et § 8) à DEUX connexions MySQL réelles, preuve d'attente
 * par InnoDB : ordre compte → fiche → CV face à la suppression et à la restauration du compte ;
 * deux envois simultanés ; interblocage sans reprise. PDF fictifs uniquement.
 */
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
class CvConcurrencyTest extends TestCase
{
    use DatabaseMigrations;
    use ForksDatabaseConnections;

    private User $admin;

    private Learner $learner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake(CvStorage::DISK);
        $this->admin = User::factory()->withRole(Role::Administrateur)->create();
        $user = User::factory()->withRole(Role::Apprenant)->create(['email' => 'apprenant@example.test']);
        $this->learner = Learner::query()->forceCreate([
            'user_id' => $user->id,
            'cnie' => 'ZZ000001',
            'campus_id' => Campus::query()->create(['name' => 'Campus Fictif'])->id,
            'promotion_id' => Promotion::query()->create(['label' => 'Promotion Fictive'])->id,
            'class_id' => SchoolClass::query()->create([
                'name' => 'CLASSE-FICTIVE',
                'filiere_id' => Filiere::query()->create(['code' => 'FIC', 'label' => 'Fictive'])->id,
            ])->id,
        ]);
    }

    private function file(string $text): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cv');
        file_put_contents($path, FakePdf::classic($text));

        return new UploadedFile($path, 'cv.pdf', 'application/pdf', null, true);
    }

    /** Enfant qui téléverse ; `$expected` : code de refus attendu, ou null pour un succès. */
    private function uploadInChild(string $text, ?string $expected = null): array
    {
        $file = $this->file($text);
        $learnerId = $this->learner->id;
        $adminId = $this->admin->id;

        return $this->fork(function () use ($file, $learnerId, $adminId, $expected): void {
            try {
                app(CvService::class)->upload(Learner::query()->findOrFail($learnerId), $file, User::query()->findOrFail($adminId));
            } catch (CvRuleException $e) {
                if ($e->errorCode !== $expected) {
                    throw $e;
                }

                return;
            }
            if ($expected !== null) {
                throw new RuntimeException("Refus « {$expected} » attendu.");
            }
        });
    }

    /** @return list<string> */
    private function files(): array
    {
        return Storage::disk(CvStorage::DISK)->files(CvStorage::DIR);
    }

    public function test_deux_envois_simultanes(): void
    {
        $first = $this->uploadInChild('Version A');
        $second = $this->uploadInChild('Version B');

        // Le parent tient le verrou du compte : les deux envois l'attendent, puis passent l'un après l'autre.
        DB::beginTransaction();
        User::withTrashed()->whereKey($this->learner->user_id)->lockForUpdate()->firstOrFail();
        $this->release($first);
        $this->release($second);
        DB::commit();
        $this->finish($first);
        $this->finish($second);

        $cv = CvDocument::query()->sole();
        $this->assertSame([$cv->path], $this->files());
        $this->assertSame([CvHistoryEntry::UPLOADED, CvHistoryEntry::REPLACED], CvHistoryEntry::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_suppression_du_compte_validee_avant_l_envoi(): void
    {
        $child = $this->uploadInChild('Version A', expected: 'learner_deleted');

        DB::beginTransaction();
        app(LearnerService::class)->delete($this->learner);
        $this->release($child);
        DB::commit();
        $this->finish($child);

        $this->assertSame(0, CvDocument::query()->count());
        $this->assertSame([], $this->files());
    }

    public function test_envoi_valide_avant_la_suppression_conserve(): void
    {
        $learnerId = $this->learner->id;
        $child = $this->fork(function () use ($learnerId): void {
            app(LearnerService::class)->delete(Learner::query()->findOrFail($learnerId));
        });

        // Le parent joue l'envoi jusqu'à la fin de sa transaction : la suppression attend le compte.
        $storage = app(CvStorage::class);
        $path = $storage->store(FakePdf::classic('Version A'));
        DB::beginTransaction();
        User::withTrashed()->whereKey($this->learner->user_id)->lockForUpdate()->firstOrFail();
        CvDocument::query()->forceCreate([
            'learner_id' => $this->learner->id, 'source' => CvDocument::SOURCE_UPLOAD, 'added_at' => now(),
            'version' => (string) Str::uuid(),
            'path' => $path, 'sha256' => hash('sha256', 'x'), 'size' => 1, 'mime' => 'application/pdf',
        ]);
        $this->release($child);
        DB::commit();
        $this->finish($child);

        $this->assertTrue($this->learner->user()->first()?->trashed());
        $this->assertSame(1, CvDocument::query()->count());
    }

    public function test_restauration_face_au_retrait(): void
    {
        $this->assertSame('created', app(CvService::class)->upload($this->learner, $this->file('Version A'), $this->admin)['status']);
        app(LearnerService::class)->delete($this->learner);
        $learnerId = $this->learner->id;
        $adminId = $this->admin->id;
        $child = $this->fork(function () use ($learnerId, $adminId): void {
            app(CvService::class)->remove(Learner::query()->findOrFail($learnerId), User::query()->findOrFail($adminId));
        });

        DB::beginTransaction();
        app(LearnerService::class)->restore($this->learner);
        $this->release($child);
        DB::commit();
        $this->finish($child);

        $this->assertSame(0, CvDocument::query()->count());
        $this->assertSame([], $this->files());
        $this->assertSame(CvHistoryEntry::REMOVED, CvHistoryEntry::query()->latest('id')->value('action'));
    }

    /**
     * Revue Codex T-20, constat 2 : une préparation ancienne (plus d'une heure) mais encore ACTIVE (verrou
     * nommé tenu par la connexion de l'envoi) n'est jamais effacée par le nettoyage d'une autre connexion ;
     * une fois le verrou libéré, elle l'est.
     */
    public function test_nettoyage_ignore_une_preparation_active(): void
    {
        // Enfant lancé AVANT le verrou du parent : son lancement rouvre la connexion du parent.
        $child = $this->fork(function (): void {
            $deleted = app(CvStorage::class)->sweepOrphans();
            if ($deleted !== 0) {
                throw new RuntimeException("Préparation active supprimée ({$deleted}).");
            }
        });

        $storage = app(CvStorage::class);
        $path = $storage->store(FakePdf::classic('Préparation active'));
        touch(Storage::disk(CvStorage::DISK)->path($path), now()->subHours(2)->getTimestamp());

        $this->releaseUntilDone($child);
        $this->finish($child);
        $this->assertSame([$path], $this->files());

        $storage->release($path);
        $this->assertSame(1, $storage->sweepOrphans());
        $this->assertSame([], $this->files());
    }

    /**
     * Contre-revue Codex T-20, N3 : une reconnexion entre la préparation et l'adoption libère le verrou
     * du fichier ; l'adoption est alors abandonnée (aucune ligne, fichier préparé effacé).
     */
    public function test_reconnexion_avant_l_adoption_abandonne(): void
    {
        $this->app->instance(CvStorage::class, new class extends CvStorage
        {
            public function store(string $plain): string
            {
                $path = parent::store($plain);
                DB::reconnect();

                return $path;
            }
        });

        try {
            app(CvService::class)->upload($this->learner, $this->file('Version A'), $this->admin);
            $this->fail('Refus « cv_not_saved » attendu.');
        } catch (CvRuleException $e) {
            $this->assertSame('cv_not_saved', $e->errorCode);
        }

        $this->assertSame(0, CvDocument::query()->count());
        $this->assertSame([], $this->files());
    }

    public function test_interblocage_echoue_sans_reprise(): void
    {
        $attempts = 0;
        CvDocument::creating(function () use (&$attempts): void {
            $attempts++;
            $pdo = new PDOException('Deadlock found when trying to get lock');
            $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
            throw new QueryException('mysql', 'insert into cv_documents', [], $pdo);
        });

        try {
            app(CvService::class)->upload($this->learner, $this->file('Version A'), $this->admin);
            $this->fail('Refus « retry » attendu.');
        } catch (CvRuleException $e) {
            $this->assertSame('retry', $e->errorCode);
        }

        $this->assertSame(1, $attempts);
        $this->assertSame(0, CvDocument::query()->count());
        $this->assertSame([], $this->files());
    }
}
