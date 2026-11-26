<?php

namespace Tests\Feature\Companies;

use App\Enums\Role;
use App\Models\Company;
use App\Models\CompanyImport;
use App\Models\Event;
use App\Models\User;
use App\Services\Companies\CompanyNameLock;
use App\Services\Companies\CompanyService;
use App\Services\Imports\CompanyImportCredentials;
use App\Services\Imports\CompanyImportRunner;
use App\Services\Imports\CompanyImportService;
use App\Support\CompanyNameKey;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Concerns\ForksDatabaseConnections;
use Tests\Support\FakeWorkbook;
use Tests\TestCase;

/**
 * Verrou commun des noms d'entreprise (plan T-64 v1.3, § 4) à DEUX connexions MySQL réelles, avec
 * preuve d'attente de verrou par InnoDB. Données fictives uniquement.
 */
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
class CompanyImportConcurrencyTest extends TestCase
{
    use DatabaseMigrations;
    use ForksDatabaseConnections;

    private User $admin;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->admin = User::factory()->withRole(Role::Administrateur)->create();
        $date = now(Event::TIMEZONE)->addMonth()->toDateString();
        $this->event = Event::query()->forceCreate(['name' => 'JobDating', 'school_year' => Event::schoolYearFor($date), 'date' => $date, 'location' => 'Campus Fictif']);
    }

    /**
     * Le retour arrière des migrations (DatabaseMigrations) refuse les comptes sans e-mail (garde-fou de
     * T-04) : les comptes entreprise fictifs reçoivent une adresse fictive avant ce retour arrière.
     */
    protected function tearDown(): void
    {
        DB::table('users')->whereNull('email')->update(['email' => DB::raw("concat('fin-', id, '@example.test')")]);
        parent::tearDown();
    }

    /** Import confirmé (en attente de traitement) d'une seule entreprise. */
    private function confirmed(string $name): int
    {
        $book = new FakeWorkbook(['Entreprises Invitées' => [1 => ['Entreprises', 'Confirmation par mail'], 2 => [$name, 'Oui']]]);
        $service = app(CompanyImportService::class);
        $plan = $service->preview($book->file(), $this->event->id)['plan'];

        return $service->confirm($book->file(), $this->event->id, $plan['fingerprint'], (string) Str::uuid(), $this->admin)->id;
    }

    private function runInChild(int $importId): array
    {
        return $this->fork(function () use ($importId): void {
            app(CompanyImportRunner::class)->run($importId);
        });
    }

    private function company(string $name, string $username): Company
    {
        return app(CompanyService::class)->create(['name' => $name, 'username' => $username], null, $this->admin, ['event_id' => $this->event->id])['company'];
    }

    /**
     * Libère l'enfant quand le renommage du parent tient son PREMIER verrou de ligne (entreprise ou
     * compte) et va prendre le second (revue Codex T-64, constat 5). Avec l'ordre attendu
     * (entreprise → compte), l'enfant (entreprise → compte) attend l'entreprise ; avec un ordre inversé,
     * les deux transactions s'attendraient mutuellement et le test échouerait.
     *
     * @param  array{pid: int, start: string, result: string, connection: string}  $child
     */
    private function releaseBetweenRenameLocks(array $child): void
    {
        $first = null;
        $released = false;
        DB::beforeExecuting(function (string $query) use (&$first, &$released, $child): void {
            if ($released || ! preg_match('/^(select .* from `(companies|users)`.* for update|update `(companies|users)`)/', $query, $m)) {
                return;
            }
            $table = $m[2] !== '' ? $m[2] : $m[3];
            if ($first === null) {
                $first = $table;
            } elseif ($table !== $first) {
                $released = true;
                $this->release($child);
            }
        });
    }

    /**
     * Exécute l'opération de l'enfant et échoue si une de ses transactions a été annulée : un
     * interblocage rejoué automatiquement par le service ne doit pas passer inaperçu.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function withoutRollback(callable $operation): mixed
    {
        $rolledBack = false;
        EventFacade::listen(TransactionRolledBack::class, function () use (&$rolledBack): void {
            $rolledBack = true;
        });
        $result = $operation();
        if ($rolledBack) {
            throw new \RuntimeException('Transaction annulée puis rejouée : interblocage.');
        }

        return $result;
    }

    private function assertConflict(int $importId): void
    {
        $import = CompanyImport::query()->findOrFail($importId);
        $this->assertSame(CompanyImport::FAILED, $import->status);
        $this->assertSame(CompanyImportRunner::CONFLICT_ERROR, $import->error);
    }

    public function test_import_contre_import_meme_nom_simplifie(): void
    {
        $second = $this->confirmed('Acme Services');
        $child = $this->runInChild($second);

        // Le parent joue un autre import : verrou des noms, création non encore validée.
        DB::beginTransaction();
        CompanyNameLock::acquire();
        app(CompanyService::class)->importCreate($this->event, 'AcmeServices', 'acmeservices', Hash::make('Fictif-123'), $this->admin);
        $this->release($child);
        DB::commit();
        $this->finish($child);

        $this->assertConflict($second);
        $this->assertSame(1, Company::query()->where('name_key', CompanyNameKey::of('Acme Services'))->count());
    }

    public function test_import_contre_creation_a_la_main(): void
    {
        $import = $this->confirmed('Acme Fictive');
        $child = $this->runInChild($import);

        DB::beginTransaction();
        $this->company('ACME fictive', 'acme-main');
        $this->release($child);
        DB::commit();
        $this->finish($child);

        $this->assertConflict($import);
    }

    public function test_import_contre_renommage(): void
    {
        $existing = $this->company('Ancien Nom', 'ancien-nom');
        $import = $this->confirmed('Acme Fictive');
        $child = $this->runInChild($import);

        DB::beginTransaction();
        app(CompanyService::class)->update($existing, ['name' => 'Acme-Fictive'], $this->admin);
        $this->release($child);
        DB::commit();
        $this->finish($child);

        $this->assertConflict($import);
    }

    public function test_renommage_contre_reinitialisation(): void
    {
        $company = $this->company('Ancien Nom', 'ancien-nom');
        $companyId = $company->id;
        $adminId = $this->admin->id;
        $child = $this->fork(function () use ($companyId, $adminId): void {
            $this->withoutRollback(fn () => app(CompanyService::class)->resetPassword(Company::query()->findOrFail($companyId), User::query()->findOrFail($adminId)));
        });

        // Renommage : noms → entreprise → compte ; la réinitialisation (entreprise → compte) attend.
        $this->releaseBetweenRenameLocks($child);
        DB::beginTransaction();
        app(CompanyService::class)->update($company, ['name' => 'Nouveau Nom'], $this->admin);
        DB::commit();
        $this->finish($child);

        $company->refresh();
        $this->assertSame('Nouveau Nom', $company->name);
        $this->assertSame(2, $company->credentials_version);
    }

    public function test_renommage_contre_marquage_des_fiches(): void
    {
        $importId = $this->confirmed('Acme Fictive');
        app(CompanyImportRunner::class)->run($importId);
        $company = Company::query()->where('name', 'Acme Fictive')->firstOrFail();
        $adminId = $this->admin->id;
        $child = $this->fork(function () use ($importId, $adminId): void {
            $result = $this->withoutRollback(fn () => app(CompanyImportCredentials::class)->markPrinted($importId, User::query()->findOrFail($adminId)));
            if ($result['marked'] !== 1) {
                throw new \RuntimeException('Fiche non marquée.');
            }
        });

        $this->releaseBetweenRenameLocks($child);
        DB::beginTransaction();
        app(CompanyService::class)->update($company, ['name' => 'Acme Fictive Renommée'], $this->admin);
        DB::commit();
        $this->finish($child);

        $this->assertNotNull($company->refresh()->credentials_printed_at);
    }
}
