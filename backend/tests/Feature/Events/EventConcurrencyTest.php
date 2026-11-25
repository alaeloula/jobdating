<?php

namespace Tests\Feature\Events;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventCompany;
use App\Models\EventLearner;
use App\Models\Learner;
use App\Models\LearnerImport;
use App\Models\Referentials\Campus;
use App\Models\Referentials\Competence;
use App\Models\Referentials\Filiere;
use App\Models\Referentials\Promotion;
use App\Models\Referentials\SchoolClass;
use App\Models\User;
use App\Services\Companies\CompanyService;
use App\Services\Events\EventParticipationService;
use App\Services\Events\EventRuleException;
use App\Services\Events\EventService;
use App\Services\Imports\LearnerImportRunner;
use App\Services\Learners\LearnerService;
use Closure;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDOException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;
use Throwable;

/**
 * Concurrence sur MySQL à DEUX connexions réelles (plan T-14, § 6) : le processus enfant exécute une
 * opération pendant que le parent détient ses verrous. Données fictives uniquement.
 */
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
class EventConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private User $admin;

    private Event $event;

    private SchoolClass $class;

    private Campus $campus;

    private Promotion $promotion;

    private const WAIT_SECONDS = 30;

    /** Durée d'observation des contrôles négatifs : aucune attente de verrou ne doit apparaître. */
    private const NEGATIVE_SECONDS = 1.5;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
        $this->admin = User::factory()->withRole(Role::Administrateur)->create();
        $date = now(Event::TIMEZONE)->addMonth()->toDateString();
        $this->event = Event::query()->forceCreate(['name' => 'JobDating', 'school_year' => Event::schoolYearFor($date), 'date' => $date, 'location' => 'Campus Fictif']);
        $this->campus = Campus::query()->create(['name' => 'Campus Fictif']);
        $this->promotion = Promotion::query()->create(['label' => 'Promotion Fictive']);
        $this->class = SchoolClass::query()->create(['name' => 'CLASSE-FICTIVE', 'filiere_id' => Filiere::query()->create(['code' => 'FIC', 'label' => 'Fictive'])->id]);
    }

    private function learner(bool $active = true): Learner
    {
        $n = ++$this->sequence;
        $user = User::factory()->withRole(Role::Apprenant)->create(['email' => "concurrence{$n}@example.test", 'deactivated_at' => $active ? null : now()]);

        return Learner::query()->forceCreate([
            'user_id' => $user->id, 'cnie' => 'ZZ'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'campus_id' => $this->campus->id, 'promotion_id' => $this->promotion->id, 'class_id' => $this->class->id,
        ]);
    }

    /**
     * Lance `$child` dans un autre processus, avec sa propre connexion. Il attend le fichier de départ.
     *
     * @return array{pid: int, start: string, result: string, connection: string}
     */
    private function fork(Closure $child): array
    {
        $start = (string) tempnam(sys_get_temp_dir(), 't14-start');
        unlink($start);
        $result = (string) tempnam(sys_get_temp_dir(), 't14-result');
        $connection = (string) tempnam(sys_get_temp_dir(), 't14-connection');
        DB::disconnect();

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $status = 'ok';
            try {
                $deadline = microtime(true) + self::WAIT_SECONDS;
                while (! file_exists($start) && microtime(true) < $deadline) {
                    usleep(5000);
                }
                DB::purge();
                DB::reconnect();
                // Numéro de SA connexion MySQL : seule celle-ci est surveillée par le parent (contre-revue T-14, R1).
                file_put_contents($connection, (string) DB::selectOne('select connection_id() as id')->id);
                $child();
            } catch (Throwable $e) {
                $status = $e::class.': '.mb_substr($e->getMessage(), 0, 200);
            }
            file_put_contents($result, $status);
            // Arrêt immédiat : aucun nettoyage de PHPUnit dans l'enfant.
            posix_kill((int) getmypid(), SIGKILL);
        }
        DB::reconnect();

        return ['pid' => $pid, 'start' => $start, 'result' => $result, 'connection' => $connection];
    }

    /**
     * Démarre l'enfant et attend que SA connexion attende un verrou détenu par la connexion du parent
     * (revue T-14, constat 6 ; contre-revue, R1). La preuve vient d'InnoDB (`data_lock_waits`), pas de
     * la durée d'une requête : une requête lente ou une autre connexion ne sont jamais acceptées.
     *
     * @param  array{pid: int, start: string, result: string, connection: string}  $child
     */
    private function release(array $child): void
    {
        touch($child['start']);
        $childId = $this->childConnection($child);
        if ($childId === null || ! $this->waitsForLockOf($childId, $this->parentConnection(), self::WAIT_SECONDS)) {
            posix_kill($child['pid'], SIGKILL);
            pcntl_waitpid($child['pid'], $status);
            $this->fail('L’opération concurrente n’a jamais attendu un verrou du parent.');
        }
    }

    /** @param array{pid: int, start: string, result: string, connection: string} $child */
    private function childConnection(array $child): ?int
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $child['connection']);
            if (filesize($child['connection']) > 0) {
                return (int) file_get_contents($child['connection']);
            }
            usleep(5000);
        }

        return null;
    }

    private function parentConnection(): int
    {
        return (int) DB::selectOne('select connection_id() as id')->id;
    }

    /**
     * Vrai dès qu'InnoDB montre la connexion `$waiting` en attente d'un verrou détenu par `$holder`,
     * observée depuis une troisième connexion ; faux à l'expiration du délai.
     */
    private function waitsForLockOf(int $waiting, int $holder, float $seconds): bool
    {
        config(['database.connections.probe' => config('database.connections.'.DB::getDefaultConnection())]);
        $probe = DB::connection('probe');
        $deadline = microtime(true) + $seconds;
        try {
            while (microtime(true) < $deadline) {
                $found = $probe->table('performance_schema.data_lock_waits as w')
                    ->join('performance_schema.threads as r', 'r.THREAD_ID', '=', 'w.REQUESTING_THREAD_ID')
                    ->join('performance_schema.threads as b', 'b.THREAD_ID', '=', 'w.BLOCKING_THREAD_ID')
                    ->where('r.PROCESSLIST_ID', $waiting)
                    ->where('b.PROCESSLIST_ID', $holder)
                    ->exists();
                if ($found) {
                    return true;
                }
                usleep(10000);
            }

            return false;
        } finally {
            DB::purge('probe');
        }
    }

    /**
     * Démarre l'enfant et attend sa FIN alors que le parent garde ses verrous : il ne doit pas en dépendre.
     *
     * @param  array{pid: int, start: string, result: string, connection: string}  $child
     */
    private function releaseUntilDone(array $child): void
    {
        touch($child['start']);
        $deadline = microtime(true) + self::WAIT_SECONDS;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $child['result']);
            if (filesize($child['result']) > 0) {
                return;
            }
            usleep(10000);
        }
        posix_kill($child['pid'], SIGKILL);
        pcntl_waitpid($child['pid'], $status);
        $this->fail('L’opération concurrente aurait dû se terminer sans attendre.');
    }

    /** @param array{pid: int, start: string, result: string, connection: string} $child */
    private function finish(array $child): void
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;
        while (pcntl_waitpid($child['pid'], $status, WNOHANG) === 0) {
            if (microtime(true) >= $deadline) {
                posix_kill($child['pid'], SIGKILL);
                pcntl_waitpid($child['pid'], $status);
                $this->fail('L’opération concurrente ne s’est pas terminée.');
            }
            usleep(10000);
        }
        $this->assertSame('ok', (string) file_get_contents($child['result']));
        @unlink($child['start']);
        @unlink($child['result']);
        @unlink($child['connection']);
    }

    /** @return array{pid: int, start: string, result: string, connection: string} */
    private function designateInChild(): array
    {
        $eventId = $this->event->id;
        $adminId = $this->admin->id;

        return $this->fork(function () use ($eventId, $adminId): void {
            app(EventService::class)->designate(Event::query()->findOrFail($eventId), User::query()->findOrFail($adminId));
        });
    }

    // ---------------------------------------------------------------- Contrôles du détecteur (R1)

    public function test_detecteur_refuse_une_requete_lente_sans_verrou(): void
    {
        $child = $this->fork(function (): void {
            DB::select('select sleep(3)');
        });
        touch($child['start']);
        $childId = $this->childConnection($child);

        $this->assertNotNull($childId);
        $this->assertFalse($this->waitsForLockOf($childId, $this->parentConnection(), self::NEGATIVE_SECONDS));
        $this->finish($child);
    }

    public function test_detecteur_ne_retient_que_la_connexion_de_l_enfant(): void
    {
        // Une connexion tierce attend vraiment le verrou du parent ; l'enfant observé, lui, est seulement lent.
        // Les enfants sont lancés AVANT la prise du verrou : un lancement rouvre la connexion du parent.
        $other = $this->fork(function (): void {
            DB::transaction(fn () => DB::table('event_locks')->where('id', 1)->lockForUpdate()->first());
        });
        $slow = $this->fork(function (): void {
            DB::select('select sleep(3)');
        });

        DB::beginTransaction();
        DB::table('event_locks')->where('id', 1)->lockForUpdate()->first();
        $parentId = $this->parentConnection();
        touch($other['start']);
        touch($slow['start']);
        $otherId = $this->childConnection($other);
        $slowId = $this->childConnection($slow);

        $this->assertNotNull($otherId);
        $this->assertNotNull($slowId);
        $this->assertTrue($this->waitsForLockOf($otherId, $parentId, self::WAIT_SECONDS));
        $this->assertFalse($this->waitsForLockOf($slowId, $parentId, self::NEGATIVE_SECONDS));

        DB::commit();
        $this->finish($other);
        $this->finish($slow);
    }

    public function test_creation_en_cours_pendant_la_designation_est_inscrite(): void
    {
        $child = $this->designateInChild();

        // Le parent joue une création : verrou partagé, écriture non validée, puis validation.
        DB::beginTransaction();
        app(EventParticipationService::class)->beginActivation();
        $learner = $this->learner();
        $this->release($child);
        DB::commit();
        $this->finish($child);

        $this->assertSame(1, EventLearner::query()->where('event_id', $this->event->id)->where('learner_id', $learner->id)->count());
    }

    public function test_creation_apres_le_verrou_de_designation_voit_le_nouveau_courant(): void
    {
        $classId = $this->class->id;
        $campusId = $this->campus->id;
        $promotionId = $this->promotion->id;
        $adminId = $this->admin->id;
        $child = $this->fork(function () use ($classId, $campusId, $promotionId, $adminId): void {
            app(LearnerService::class)->create([
                'cnie' => 'ZZ999999', 'first_name' => 'Fictif', 'last_name' => 'Concurrent', 'email' => 'concurrent@example.test',
                'campus_id' => $campusId, 'promotion_id' => $promotionId, 'class_id' => $classId,
            ], User::query()->findOrFail($adminId));
        });

        DB::beginTransaction();
        DB::table('event_locks')->where('id', 1)->lockForUpdate()->first();
        $this->event->forceFill(['is_current' => true])->save();
        $this->release($child);
        DB::commit();
        $this->finish($child);

        $id = Learner::query()->where('cnie', 'ZZ999999')->value('id');
        $this->assertSame(1, EventLearner::query()->where('event_id', $this->event->id)->where('learner_id', $id)->count());
    }

    /**
     * Libère l'enfant à la première écriture de comptes : le parent détient alors ses verrous.
     *
     * @param  array{pid: int, start: string, result: string, connection: string}  $child
     */
    private function releaseOnUserUpdate(array $child, bool &$released): void
    {
        DB::beforeExecuting(function (string $query) use (&$released, $child): void {
            if (! $released && str_contains($query, 'update `users`')) {
                $released = true;
                $this->release($child);
            }
        });
    }

    public function test_modification_d_un_apprenant_contre_designation(): void
    {
        $learner = $this->learner();
        $child = $this->designateInChild();
        $released = false;
        $this->releaseOnUserUpdate($child, $released);

        app(LearnerService::class)->update($learner, ['first_name' => 'Modifié'], $this->admin);
        $this->finish($child);

        $this->assertTrue($released);
        $this->assertSame('Modifié', $learner->user->refresh()->first_name);
        $this->assertSame(1, EventLearner::query()->where('learner_id', $learner->id)->count());
    }

    public function test_desactivation_en_masse_contre_designation(): void
    {
        $learners = [$this->learner(), $this->learner(), $this->learner()];
        $child = $this->designateInChild();
        // La désignation ne lit les comptes que sans verrou : elle se termine pendant la désactivation.
        $released = false;
        DB::beforeExecuting(function (string $query) use (&$released, $child): void {
            if (! $released && str_contains($query, 'update `users`')) {
                $released = true;
                $this->releaseUntilDone($child);
            }
        });

        $changed = app(LearnerService::class)->bulkDeactivate(
            Learner::query()->join('users', 'users.id', '=', 'learners.user_id')->where('learners.id', $learners[0]->id)->select('learners.*'),
            1,
            ['mode' => 'apprenants cochés'],
            $this->admin,
        );
        $this->finish($child);

        $this->assertTrue($released);
        $this->assertSame(1, $changed);
        // Une ligne éventuelle du compte désactivé reste invisible.
        $visible = Learner::query()->participatingIn($this->event->id)->pluck('id')->all();
        $this->assertSame([$learners[1]->id, $learners[2]->id], $visible);
    }

    public function test_import_pendant_la_designation_voit_le_nouveau_courant(): void
    {
        Queue::fake();
        $headers = ['ID', 'Prénom', 'Nom', 'Nom Complet', 'E-mail Personnel', 'E-mail Professionnel', 'Classe', 'Téléphone', 'Ville', 'Date de Naissance', 'CNIE', 'Statut'];
        $row = ['#1', 'Fictif', 'Importé', 'Fictif Importé', 'perso@example.test', 'importe@example.test', 'CLASSE-FICTIVE', '0600000000', 'Ville', '01/01/1990', 'ZZ888888', 'Actif'];
        $content = implode("\n", array_map(fn (array $cells) => implode(',', array_map(fn (string $c) => '"'.$c.'"', $cells)), [$headers, $row]))."\n";
        $file = fn () => UploadedFile::fake()->createWithContent('export.csv', $content);
        $base = ['campus_id' => $this->campus->id, 'promotion_id' => $this->promotion->id];
        $this->withHeader('Origin', 'http://localhost');
        $fingerprint = $this->actingAs($this->admin, 'web')->post('/api/v1/learner-imports/preview', $base + ['file' => $file()], ['Accept' => 'application/json'])
            ->assertOk()->json('data.fingerprint');
        $importId = (int) $this->post('/api/v1/learner-imports', $base + ['file' => $file(), 'fingerprint' => $fingerprint, 'confirmation_key' => (string) Str::uuid()], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');

        $child = $this->fork(function () use ($importId): void {
            app(LearnerImportRunner::class)->run($importId);
        });

        DB::beginTransaction();
        DB::table('event_locks')->where('id', 1)->lockForUpdate()->first();
        $this->event->forceFill(['is_current' => true])->save();
        $this->release($child);
        DB::commit();
        $this->finish($child);

        $this->assertSame(LearnerImport::DONE, LearnerImport::query()->whereKey($importId)->value('status'));
        $id = Learner::query()->where('cnie', 'ZZ888888')->value('id');
        $this->assertSame(1, EventLearner::query()->where('event_id', $this->event->id)->where('learner_id', $id)->count());
    }

    /** Création d'entreprise attendant une archivage ou une suppression non validée : refus, rien de créé. */
    private function companyCreationRefusedAfter(Closure $parentChange): void
    {
        $eventId = $this->event->id;
        $adminId = $this->admin->id;
        $child = $this->fork(function () use ($eventId, $adminId): void {
            try {
                app(CompanyService::class)->create(['name' => 'Concurrente', 'username' => 'concurrente'], null, User::query()->findOrFail($adminId), ['event_id' => $eventId]);
            } catch (ValidationException $e) {
                if (array_key_exists('event_id', $e->errors())) {
                    return;
                }
                throw $e;
            }
            throw new \RuntimeException('La création aurait dû être refusée.');
        });

        DB::beginTransaction();
        $locked = Event::query()->whereKey($eventId)->lockForUpdate()->firstOrFail();
        $parentChange($locked);
        $this->release($child);
        DB::commit();
        $this->finish($child);

        $this->assertSame(0, Company::query()->count());
        $this->assertSame(0, EventCompany::query()->count());
        $this->assertNull(User::query()->where('username', 'concurrente')->first());
    }

    public function test_creation_entreprise_contre_archivage(): void
    {
        $this->companyCreationRefusedAfter(fn (Event $event) => $event->forceFill(['archived_at' => now()])->save());
    }

    public function test_creation_entreprise_contre_suppression(): void
    {
        $this->companyCreationRefusedAfter(fn (Event $event) => $event->delete());
    }

    public function test_ajout_de_competence_en_masse_chevauche_la_designation(): void
    {
        $learners = [];
        for ($i = 0; $i < 40; $i++) {
            $learners[] = $this->learner();
        }
        $competence = Competence::query()->create(['label' => 'Compétence fictive']);
        $child = $this->designateInChild();

        // Au premier ajout de compétence, le parent détient déjà les comptes et les fiches : l'enfant
        // démarre alors et se heurte réellement à ces verrous.
        $released = false;
        DB::beforeExecuting(function (string $query) use (&$released, $child): void {
            if (! $released && str_contains($query, 'insert into `learner_competence`')) {
                $released = true;
                $this->release($child);
            }
        });

        $changed = app(LearnerService::class)->bulkAddCompetence(
            Learner::query()->join('users', 'users.id', '=', 'learners.user_id')->select('learners.*'),
            40,
            $competence->id,
            ['mode' => 'filtre'],
            $this->admin,
        );
        $this->finish($child);

        $this->assertTrue($released);
        $this->assertSame(40, $changed);
        $this->assertSame(40, DB::table('learner_competence')->where('competence_id', $competence->id)->count());
        $this->assertSame(40, EventLearner::query()->where('event_id', $this->event->id)->whereNull('removed_at')->count());
    }

    public function test_interblocage_rejoue_avec_une_seule_ligne_de_journal(): void
    {
        $this->learner();
        $failures = 1;
        DB::beforeExecuting(function (string $query) use (&$failures): void {
            if ($failures > 0 && str_contains($query, 'insert ignore into `event_learners`')) {
                $failures--;
                throw $this->deadlock($query);
            }
        });

        $result = app(EventService::class)->designate($this->event, $this->admin);

        $this->assertSame(1, $result['report']['enrolled']);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::EventCurrentSet->value)->count());
        $this->assertSame(1, EventLearner::query()->count());
    }

    public function test_interblocages_epuises_rien_n_est_modifie(): void
    {
        $this->learner();
        DB::beforeExecuting(function (string $query): void {
            if (str_contains($query, 'insert ignore into `event_learners`')) {
                throw $this->deadlock($query);
            }
        });

        try {
            app(EventService::class)->designate($this->event, $this->admin);
            $this->fail('Une erreur 409 était attendue.');
        } catch (EventRuleException $e) {
            $this->assertSame('event_busy', $e->errorCode);
        }

        $this->assertFalse($this->event->refresh()->is_current);
        $this->assertSame(0, EventLearner::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::EventCurrentSet->value)->count());
    }

    private function deadlock(string $query): QueryException
    {
        $pdo = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
        $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

        return new QueryException('mysql', $query, [], $pdo);
    }
}
