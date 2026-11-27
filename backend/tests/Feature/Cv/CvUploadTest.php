<?php

namespace Tests\Feature\Cv;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\CvDocument;
use App\Models\CvHistoryEntry;
use App\Models\Learner;
use App\Models\Referentials\Campus;
use App\Models\Referentials\Filiere;
use App\Models\Referentials\Promotion;
use App\Models\Referentials\SchoolClass;
use App\Models\User;
use App\Services\Cv\CvService;
use App\Services\Cv\CvStorage;
use App\Services\Cv\PdfInspector;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Tests\Support\FakePdf;
use Tests\TestCase;

/**
 * Téléversement, remplacement et retrait du CV (CV-01, CV-04, RG-CV-05, RG-CV-06, DECISIONS 26/11 ;
 * plan T-20 v1.2). PDF fictifs uniquement.
 */
class CvUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $cme;

    private User $direction;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake(CvStorage::DISK);
        $this->admin = User::factory()->withRole(Role::Administrateur)->create(['first_name' => 'Admin', 'last_name' => 'Fictif']);
        $this->cme = User::factory()->withRole(Role::Cme)->create(['first_name' => 'Membre', 'last_name' => 'Cme']);
        $this->direction = User::factory()->withRole(Role::Direction)->create();
        $this->withHeader('Origin', 'http://localhost');
        // Transaction englobante de RefreshDatabase : ce n'est pas un appel dans une transaction ouverte.
        config(['cv.transaction_base' => DB::transactionLevel()]);
    }

    private function learner(bool $active = true): Learner
    {
        $n = ++$this->sequence;
        $class = SchoolClass::query()->first() ?? SchoolClass::query()->create([
            'name' => 'CLASSE-FICTIVE',
            'filiere_id' => Filiere::query()->create(['code' => 'FIC', 'label' => 'Fictive'])->id,
        ]);
        $user = User::factory()->withRole(Role::Apprenant)->create([
            'first_name' => 'Prénom'.$n,
            'last_name' => 'Fictif',
            'email' => "apprenant{$n}@example.test",
            'deactivated_at' => $active ? null : now(),
        ]);

        return Learner::query()->forceCreate([
            'user_id' => $user->id,
            'cnie' => 'ZZ'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'campus_id' => (Campus::query()->first() ?? Campus::query()->create(['name' => 'Campus Fictif']))->id,
            'promotion_id' => (Promotion::query()->first() ?? Promotion::query()->create(['label' => 'Promotion Fictive']))->id,
            'class_id' => $class->id,
        ]);
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    private function send(User $user, Learner $learner, UploadedFile $file): TestResponse
    {
        return $this->as($user)->post("/api/v1/learners/{$learner->id}/cv", ['file' => $file], ['Accept' => 'application/json']);
    }

    /** @return list<string> */
    private function files(): array
    {
        return Storage::disk(CvStorage::DISK)->files(CvStorage::DIR);
    }

    // ---------------------------------------------------------------- Droits

    public function test_droits_des_cinq_roles_et_sans_session(): void
    {
        $learner = $this->learner();
        $company = User::factory()->withRole(Role::Entreprise)->create();
        $apprenant = $learner->user;
        $pdf = FakePdf::classic();

        foreach ([$this->direction, $company, $apprenant] as $user) {
            $this->send($user, $learner, FakePdf::upload($pdf))->assertForbidden();
            $this->as($user)->deleteJson("/api/v1/learners/{$learner->id}/cv")->assertForbidden();
            $this->as($user)->getJson("/api/v1/learners/{$learner->id}/cv/history")->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->post("/api/v1/learners/{$learner->id}/cv", ['file' => FakePdf::upload($pdf)], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->assertSame([], $this->files());

        $this->send($this->cme, $learner, FakePdf::upload($pdf))->assertCreated()->assertJsonPath('status', 'created');
        $this->as($this->cme)->getJson("/api/v1/learners/{$learner->id}/cv/history")->assertOk()->assertJsonPath('meta.total', 1);

        // La Direction voit l'état du CV (cv.view), sans chemin ni empreinte.
        $show = $this->as($this->direction)->getJson("/api/v1/learners/{$learner->id}")->assertOk();
        $this->assertSame('televerse', $show->json('data.cv.source'));
        $this->assertSame('Membre Cme', $show->json('data.cv.added_by_name'));
        $this->assertStringNotContainsString(CvStorage::DIR.'/', (string) $show->getContent());
        $this->assertStringNotContainsString(CvDocument::query()->sole()->sha256, (string) $show->getContent());
    }

    // ---------------------------------------------------------------- Ajout, chiffrement, historique

    public function test_pdf_valide_stocke_chiffre_et_trace(): void
    {
        $learner = $this->learner();
        $pdf = FakePdf::classic();

        $response = $this->send($this->admin, $learner, FakePdf::upload($pdf, 'Prenom1-Fictif-secret.pdf'))->assertCreated();
        $this->assertSame(['source', 'added_at', 'added_by_name', 'size'], array_keys($response->json('data')));

        $cv = CvDocument::query()->sole();
        $this->assertSame([$cv->path], $this->files());
        $this->assertStringNotContainsString('Prenom', $cv->path);
        $stored = (string) Storage::disk(CvStorage::DISK)->get($cv->path);
        $this->assertStringStartsNotWith('%PDF-', $stored);
        $this->assertSame($pdf, Crypt::decryptString($stored));
        $this->assertSame(hash('sha256', $pdf), $cv->sha256);
        $this->assertSame(strlen($pdf), $cv->size);

        $entry = CvHistoryEntry::query()->sole();
        $this->assertSame([CvHistoryEntry::UPLOADED, 'televerse', 'Admin Fictif'], [$entry->action, $entry->source, $entry->user_name]);

        $log = AuditLog::query()->where('action', AuditAction::CvUploaded->value)->sole();
        $this->assertSame($learner->id, $log->subject_id);
        $this->assertStringNotContainsString('secret', (string) json_encode($log->toArray()));
    }

    public function test_lecture_avec_une_ancienne_cle(): void
    {
        $learner = $this->learner();
        $this->send($this->admin, $learner, FakePdf::upload(FakePdf::classic()))->assertCreated();
        $path = CvDocument::query()->sole()->path;

        $old = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$old]]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        $this->assertSame(FakePdf::classic(), app(CvStorage::class)->read($path));
    }

    // ---------------------------------------------------------------- Contrôle du fichier

    /** @return array<string, array{0: string}> */
    public static function refusedFiles(): array
    {
        $classic = FakePdf::classic();

        return [
            'vide' => [''],
            'png renommé' => ["\x89PNG\r\n\x1a\n".str_repeat("\0", 64)],
            'faux PDF de 67 octets' => [str_pad('%PDF-1.7', 67, ' ')],
            'PDF tronqué' => [substr($classic, 0, -40)],
            'PDF sans page' => [str_replace(['/Type /Page /', '/Count 1'], ['/Type /Xxxx /', '/Count 0'], $classic)],
            'sans startxref' => [str_replace('startxref', 'xxxxxxxxx', $classic)],
            'sans table des références' => [str_replace(["xref\n0 6", 'trailer'], ["zzzz\n0 6", 'trailer'], $classic)],
            'plus de 5 Mo' => [FakePdf::ofSize(CvService::MAX_BYTES + 1)],
        ];
    }

    /** Motif précis de chaque refus de structure. */
    public function test_motifs_de_structure(): void
    {
        $classic = FakePdf::classic();
        $this->assertSame('signature', PdfInspector::problem(str_pad('Texte fictif renommé en PDF', 64, ' ')));
        $this->assertSame('fin_de_fichier', PdfInspector::problem(substr($classic, 0, -40)));
        $this->assertSame('objets', PdfInspector::problem(str_pad('%PDF-1.7', 67, ' ')."\nxref\nstartxref\n0\n%%EOF\n"));
        $this->assertSame('page', PdfInspector::problem(str_replace(['/Type /Page /', '/Count 1'], ['/Type /Xxxx /', '/Count 0'], $classic)));
    }

    #[DataProvider('refusedFiles')]
    public function test_fichier_refuse_sans_rien_ecrire(string $bytes): void
    {
        $learner = $this->learner();

        $this->send($this->admin, $learner, FakePdf::upload($bytes))
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', CvService::INVALID_MESSAGE);

        $this->assertSame([], $this->files());
        $this->assertSame(0, CvDocument::query()->count());
        $this->assertSame(0, CvHistoryEntry::query()->count());
    }

    /** @return array<string, array{0: string}> */
    public static function acceptedFiles(): array
    {
        return [
            'table classique' => [FakePdf::classic()],
            'objets compressés' => [FakePdf::compressedObjects()],
            // Seulement le marqueur : le vrai PDF linéarisé est dans tests/Fixtures/cv (V2).
            'marqueur de linéarisation' => [FakePdf::linearized()],
            'longueur indirecte' => [FakePdf::indirectLength()],
            // Décision 9 révisée (27/11) : le contenu actif n'est pas recherché ; la protection est
            // assurée à la consultation (T-21). Ce test documente ce choix.
            'avec JavaScript (protection à la consultation, T-21)' => [FakePdf::classic(catalogExtra: '/OpenAction << /S /JavaScript /JS (app.alert(1)) >> ')],
            'texte mentionnant /JavaScript' => [FakePdf::classic('Expert /JavaScript et /JS : voir https://example.test/JS')],
            'exactement 5 Mo' => [FakePdf::ofSize(CvService::MAX_BYTES)],
        ];
    }

    #[DataProvider('acceptedFiles')]
    public function test_pdf_valide_accepte(string $bytes): void
    {
        $this->assertNull(PdfInspector::problem($bytes));
        $this->send($this->admin, $this->learner(), FakePdf::upload($bytes))->assertCreated();
    }

    /**
     * PDF fictifs de `tests/Fixtures/cv` : vrai PDF linéarisé, longueur rangée dans un flux d'objets
     * compressé, mise à jour incrémentale (vérification Codex T-20, V2 ; validés par qpdf) et, plus
     * tard, exports fictifs Word, LibreOffice, Canva et navigateur. Chacun est accepté par l'API.
     */
    public function test_fichiers_fictifs_du_dossier_acceptes(): void
    {
        $files = glob(base_path('tests/Fixtures/cv/*.pdf')) ?: [];
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $bytes = (string) file_get_contents($file);
            $this->assertNull(PdfInspector::problem($bytes), basename($file));
            $this->send($this->admin, $this->learner(), FakePdf::upload($bytes))->assertCreated();
        }
        $this->assertTrue(str_contains((string) file_get_contents(base_path('tests/Fixtures/cv/fictif-linearise.pdf')), '/Linearized 1 /L 1210'));
    }

    public function test_depassements_de_taille(): void
    {
        $learner = $this->learner();

        // Fichier refusé par PHP (UPLOAD_ERR_INI_SIZE), la requête arrive : règle `uploaded`.
        $path = tempnam(sys_get_temp_dir(), 'cv');
        file_put_contents($path, FakePdf::classic());
        $tooBig = new UploadedFile($path, 'cv.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);
        $this->send($this->admin, $learner, $tooBig)->assertUnprocessable()->assertJsonPath('errors.file.0', CvService::INVALID_MESSAGE);

        // Corps de requête au-delà de post_max_size : message du CV sur cette route seulement.
        $huge = ['CONTENT_LENGTH' => (string) (64 * 1024 * 1024), 'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'];
        $this->as($this->admin)->call('POST', "/api/v1/learners/{$learner->id}/cv", [], [], [], $huge)
            ->assertUnprocessable()->assertJsonPath('errors.file.0', CvService::INVALID_MESSAGE);
        $this->as($this->admin)->call('POST', '/api/v1/learner-imports/preview', [], [], [], $huge)->assertStatus(413);
        $this->assertSame([], $this->files());
    }

    // ---------------------------------------------------------------- Remplacement, inchangé, retrait

    public function test_remplacement_puis_meme_fichier_inchange(): void
    {
        $learner = $this->learner();
        $this->send($this->admin, $learner, FakePdf::upload(FakePdf::classic('Version A')))->assertCreated();
        $first = CvDocument::query()->sole();

        $this->travel(2)->days();
        $second = FakePdf::classic('Version B');
        $this->send($this->cme, $learner, FakePdf::upload($second))->assertOk()->assertJsonPath('status', 'replaced');

        $cv = CvDocument::query()->sole();
        $this->assertSame($first->id, $cv->id);
        $this->assertSame('Membre Cme', $cv->added_by_name);
        $this->assertTrue($cv->added_at->greaterThan($first->added_at));
        $this->assertSame([$cv->path], $this->files());
        $this->assertSame($second, app(CvStorage::class)->read($cv->path));
        $this->assertSame([CvHistoryEntry::UPLOADED, CvHistoryEntry::REPLACED], CvHistoryEntry::query()->orderBy('id')->pluck('action')->all());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::CvReplaced->value)->count());

        // Même fichier : rien ne change (décision 5), fichier préparé supprimé.
        $this->send($this->admin, $learner, FakePdf::upload($second))->assertOk()->assertJsonPath('unchanged', true);
        $this->assertSame('Membre Cme', CvDocument::query()->sole()->added_by_name);
        $this->assertSame([$cv->path], $this->files());
        $this->assertSame(2, CvHistoryEntry::query()->count());
    }

    public function test_retrait_puis_second_retrait(): void
    {
        $learner = $this->learner();
        $this->send($this->admin, $learner, FakePdf::upload(FakePdf::classic()))->assertCreated();

        $this->as($this->cme)->deleteJson("/api/v1/learners/{$learner->id}/cv")->assertOk();
        $this->assertSame(0, CvDocument::query()->count());
        $this->assertSame([], $this->files());
        $this->assertSame(CvHistoryEntry::REMOVED, CvHistoryEntry::query()->latest('id')->value('action'));
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::CvRemoved->value)->count());
        $this->assertNull($this->as($this->admin)->getJson("/api/v1/learners/{$learner->id}")->json('data.cv'));

        $this->as($this->cme)->deleteJson("/api/v1/learners/{$learner->id}/cv")->assertStatus(409)->assertJsonPath('code', 'no_cv');
    }

    // ---------------------------------------------------------------- Désactivé, supprimé

    public function test_apprenant_desactive_accepte(): void
    {
        $this->send($this->cme, $this->learner(active: false), FakePdf::upload(FakePdf::classic()))->assertCreated();
    }

    public function test_apprenant_supprime_refuse_et_cv_conserve(): void
    {
        $learner = $this->learner();
        $this->send($this->admin, $learner, FakePdf::upload(FakePdf::classic('Avant')))->assertCreated();
        $this->as($this->admin)->deleteJson("/api/v1/learners/{$learner->id}")->assertSuccessful();

        // CME : la fiche n'existe pas pour elle (404), historique compris.
        $this->send($this->cme, $learner, FakePdf::upload(FakePdf::classic('Après')))->assertNotFound();
        $this->as($this->cme)->getJson("/api/v1/learners/{$learner->id}/cv/history")->assertNotFound();
        // Administrateur : 409, historique visible.
        $this->send($this->admin, $learner, FakePdf::upload(FakePdf::classic('Après')))->assertStatus(409)->assertJsonPath('code', 'learner_deleted');
        $this->as($this->admin)->deleteJson("/api/v1/learners/{$learner->id}/cv")->assertStatus(409)->assertJsonPath('code', 'learner_deleted');
        $this->as($this->admin)->getJson("/api/v1/learners/{$learner->id}/cv/history")->assertOk();
        $this->assertCount(1, $this->files());

        $this->as($this->admin)->postJson("/api/v1/learners/{$learner->id}/restore")->assertOk();
        $this->assertSame('televerse', $this->as($this->cme)->getJson("/api/v1/learners/{$learner->id}")->json('data.cv.source'));
    }

    /**
     * Revue Codex T-20, constat 4 : suppression du compte APRÈS la recherche de la fiche, découverte sous
     * le verrou du compte. CME : 404 (comme la fiche) ; administrateur : 409.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function deletedDuringUpload(): array
    {
        return ['CME' => ['cme', 404], 'administrateur' => ['admin', 409]];
    }

    #[DataProvider('deletedDuringUpload')]
    public function test_suppression_pendant_l_envoi_selon_le_role(string $who, int $status): void
    {
        $learner = $this->learner();
        $this->app->instance(CvStorage::class, new class($learner->user_id) extends CvStorage
        {
            public function __construct(private readonly int $userId) {}

            public function store(string $plain): string
            {
                User::query()->whereKey($this->userId)->firstOrFail()->delete();

                return parent::store($plain);
            }
        });

        $response = $this->send($who === 'cme' ? $this->cme : $this->admin, $learner, FakePdf::upload(FakePdf::classic()))->assertStatus($status);
        if ($status === 409) {
            $response->assertJsonPath('code', 'learner_deleted');
        }
        $this->assertSame(0, CvDocument::query()->count());
        $this->assertSame([], $this->files());
    }

    // ---------------------------------------------------------------- Cycle de vie des fichiers

    public function test_ecriture_refusee_par_le_disque(): void
    {
        $this->app->instance(CvStorage::class, new class extends CvStorage
        {
            public function store(string $plain): string
            {
                throw new RuntimeException('disque plein');
            }
        });
        $learner = $this->learner();

        $this->send($this->admin, $learner, FakePdf::upload(FakePdf::classic()))->assertStatus(500)->assertJsonPath('code', 'cv_not_saved');
        $this->assertSame(0, CvDocument::query()->count());
    }

    public function test_echec_de_l_historique_annule_tout_et_garde_l_ancien(): void
    {
        $learner = $this->learner();
        $this->send($this->admin, $learner, FakePdf::upload(FakePdf::classic('Ancien')))->assertCreated();
        $old = CvDocument::query()->sole();

        CvHistoryEntry::creating(function (): void {
            throw new RuntimeException('historique indisponible');
        });
        $this->withoutExceptionHandling();
        try {
            $this->send($this->admin, $learner, FakePdf::upload(FakePdf::classic('Nouveau')));
            $this->fail('Une exception était attendue.');
        } catch (RuntimeException $e) {
            $this->assertSame('historique indisponible', $e->getMessage());
        }

        $this->assertSame($old->path, CvDocument::query()->sole()->path);
        $this->assertSame([$old->path], $this->files());
        $this->assertSame(FakePdf::classic('Ancien'), app(CvStorage::class)->read($old->path));
    }

    public function test_fichier_prepare_disparu_avant_l_adoption(): void
    {
        $this->app->instance(CvStorage::class, new class extends CvStorage
        {
            public function exists(string $path): bool
            {
                return false;
            }
        });
        $learner = $this->learner();

        $this->send($this->admin, $learner, FakePdf::upload(FakePdf::classic()))->assertStatus(500)->assertJsonPath('code', 'cv_not_saved');
        $this->assertSame(0, CvDocument::query()->count());
        $this->assertSame([], $this->files());
    }

    public function test_ancien_fichier_non_supprime_puis_repris_par_le_nettoyage(): void
    {
        // Le contrôleur (et donc le stockage) est réutilisé d'une requête à l'autre : stockage lié AVANT.
        $storage = new class extends CvStorage
        {
            public bool $failDeletes = false;

            public function delete(string $path): bool
            {
                return $this->failDeletes ? false : parent::delete($path);
            }
        };
        $this->app->instance(CvStorage::class, $storage);
        $learner = $this->learner();
        $this->send($this->admin, $learner, FakePdf::upload(FakePdf::classic('Ancien')))->assertCreated();
        $old = CvDocument::query()->sole()->path;
        $storage->failDeletes = true;

        $this->send($this->admin, $learner, FakePdf::upload(FakePdf::classic('Nouveau')))->assertOk();
        $new = CvDocument::query()->sole()->path;
        $this->assertNotSame($old, $new);
        $this->assertEqualsCanonicalizing([$old, $new], $this->files());

        $storage->failDeletes = false;
        touch(Storage::disk(CvStorage::DISK)->path($old), now()->subHours(2)->getTimestamp());
        touch(Storage::disk(CvStorage::DISK)->path($new), now()->subHours(2)->getTimestamp());
        Artisan::call('imports:cleanup');
        $this->assertSame([$new], $this->files());
        $this->assertSame(FakePdf::classic('Nouveau'), app(CvStorage::class)->read($new));
    }

    /** Contre-revue Codex T-20, N4 : une erreur après la prise du verrou le libère et n'écrit rien. */
    public function test_erreur_de_preparation_libere_le_verrou(): void
    {
        Str::createUuidsUsing(fn () => Uuid::fromString('00000000-0000-4000-8000-000000000001'));
        Crypt::shouldReceive('encryptString')->andThrow(new RuntimeException('chiffrement indisponible'));
        try {
            app(CvStorage::class)->store(FakePdf::classic());
            $this->fail('Une exception était attendue.');
        } catch (RuntimeException $e) {
            $this->assertSame('chiffrement indisponible', $e->getMessage());
        } finally {
            Str::createUuidsNormally();
        }

        $this->assertSame(1, (int) DB::selectOne("select is_free_lock('cv-file:00000000-0000-4000-8000-000000000001') as free")->free);
        $this->assertSame([], $this->files());
    }

    /** Vérification Codex T-20, V1 : disque impossible à ouvrir → aucun verrou pris. */
    public function test_disque_indisponible_ne_prend_pas_de_verrou(): void
    {
        $root = (string) tempnam(sys_get_temp_dir(), 'cv-racine');
        config(['filesystems.disks.'.CvStorage::DISK.'.root' => $root.'/sous-dossier']);
        Storage::forgetDisk(CvStorage::DISK);
        Str::createUuidsUsing(fn () => Uuid::fromString('00000000-0000-4000-8000-000000000002'));
        try {
            app(CvStorage::class)->store(FakePdf::classic());
            $this->fail('Une exception était attendue.');
        } catch (\Throwable) {
            // Erreur du disque attendue.
        } finally {
            Str::createUuidsNormally();
            @unlink($root);
        }

        $this->assertSame(1, (int) DB::selectOne("select is_free_lock('cv-file:00000000-0000-4000-8000-000000000002') as free")->free);
    }

    public function test_nettoyage_garde_les_fichiers_recents_et_references(): void
    {
        $disk = Storage::disk(CvStorage::DISK);
        $disk->put('cvs/ancien-orphelin.bin', 'x');
        $disk->put('cvs/recent-orphelin.bin', 'x');
        touch($disk->path('cvs/ancien-orphelin.bin'), now()->subHours(2)->getTimestamp());

        $this->assertSame(1, app(CvStorage::class)->sweepOrphans());
        $this->assertSame(['cvs/recent-orphelin.bin'], $this->files());
    }

    public function test_appel_dans_une_transaction_ouverte_refuse(): void
    {
        $learner = $this->learner();

        $this->expectException(LogicException::class);
        try {
            DB::transaction(fn () => app(CvService::class)->upload($learner, FakePdf::upload(FakePdf::classic()), $this->admin));
        } finally {
            $this->assertSame([], $this->files());
        }
    }

    // ---------------------------------------------------------------- Recherche et historique

    public function test_filtre_cv_et_compteur_sans_cv(): void
    {
        $with = $this->learner();
        $this->learner();
        $this->learner();
        $this->send($this->admin, $with, FakePdf::upload(FakePdf::classic()))->assertCreated();

        $without = $this->as($this->cme)->postJson('/api/v1/learners/search', ['cv' => 'without'])->assertOk();
        $this->assertSame(2, $without->json('meta.total'));
        $this->assertSame(2, $without->json('counts.without_cv'));

        $withCv = $this->as($this->admin)->postJson('/api/v1/learners/search', ['cv' => 'with'])->assertOk();
        $this->assertSame([$with->id], array_column($withCv->json('data'), 'id'));
        $this->assertSame(2, $withCv->json('counts.without_cv'));

        $this->as($this->admin)->postJson('/api/v1/learners/search', ['cv' => 'autre'])->assertUnprocessable();
    }

    public function test_historique_du_plus_recent_au_plus_ancien(): void
    {
        $learner = $this->learner();
        $this->send($this->admin, $learner, FakePdf::upload(FakePdf::classic('A')))->assertCreated();
        $this->travel(1)->minutes();
        $this->send($this->cme, $learner, FakePdf::upload(FakePdf::classic('B')))->assertOk();
        $this->travel(1)->minutes();
        $this->as($this->admin)->deleteJson("/api/v1/learners/{$learner->id}/cv")->assertOk();

        $history = $this->as($this->cme)->getJson("/api/v1/learners/{$learner->id}/cv/history")->assertOk();
        $this->assertSame(['retire', 'remplace', 'televerse'], array_column($history->json('data'), 'action'));
        $this->assertSame(['Admin Fictif', 'Membre Cme', 'Admin Fictif'], array_column($history->json('data'), 'author'));
        $this->assertStringNotContainsString('sha256', (string) $history->getContent());
    }
}
