<?php

namespace Tests\Feature\Passwords;

use App\Enums\AuditAction;
use App\Enums\LinkRequest;
use App\Enums\PasswordTokenType;
use App\Enums\Role;
use App\Jobs\LinkEmailTransportException;
use App\Jobs\RecordLinkSent;
use App\Jobs\SendPasswordLink;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\PasswordLinkNotification;
use App\Services\AuditLogger;
use App\Services\Passwords\AccountActivationService;
use App\Services\Passwords\PasswordTokenService;
use Database\Seeders\RolesAndPermissionsSeeder;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Tâche d'envoi d'un lien (plan v3, sections 4, 9 et 10) : décision en arrière-plan, reprises,
 * journal et auteur.
 */
class SendPasswordLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function runJob(SendPasswordLink $job): void
    {
        $job->handle(app(PasswordTokenService::class));
    }

    public function test_oubli_compte_actif_lien_de_reinitialisation_et_journal_visiteur(): void
    {
        Notification::fake();
        $user = User::factory()->withRole(Role::Apprenant)->create(['email' => 'eleve@example.test']);

        $this->runJob(SendPasswordLink::forPublicRequest(LinkRequest::Forgot, 'ELEVE@example.test'));

        $token = '';
        Notification::assertSentTo($user, PasswordLinkNotification::class, function (PasswordLinkNotification $n) use (&$token) {
            $token = explode('#', $n->url())[1] ?? '';

            return str_starts_with($n->url(), 'http://localhost:5173/reinitialiser#') && strlen($token) === 64;
        });
        $this->assertSame(1, DB::table('password_tokens')->where(['user_id' => $user->id, 'type' => 'reset'])->count());

        $log = AuditLog::query()->where('action', AuditAction::PasswordResetLinkSent->value)->firstOrFail();
        $this->assertNull($log->user_id);
        $this->assertSame(AuditLogger::VISITOR_LABEL, $log->actor_label);
        $this->assertSame($user->id, $log->subject_id);
        $this->assertEquals(['lien' => 'réinitialisation', 'origine' => 'demande publique'], $log->new_values);
        // Jamais de jeton dans le journal.
        $this->assertStringNotContainsString($token, json_encode(AuditLog::query()->get()->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_oubli_compte_non_active_nouveau_lien_d_activation(): void
    {
        Notification::fake();
        $cme = User::factory()->unactivated()->withRole(Role::Cme)->create(['email' => 'cme@example.test']);

        $this->runJob(SendPasswordLink::forPublicRequest(LinkRequest::Forgot, 'cme@example.test'));

        Notification::assertSentTo($cme, PasswordLinkNotification::class, fn (PasswordLinkNotification $n) => str_contains($n->url(), '/activation#'));
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::ActivationLinkSent->value)->count());
    }

    public function test_aucun_envoi_ni_journal_pour_un_compte_ineligible(): void
    {
        Notification::fake();
        User::factory()->withRole(Role::Entreprise)->create();
        User::factory()->deactivated()->withRole(Role::Apprenant)->create(['email' => 'off@example.test']);
        User::factory()->withRole(Role::Direction)->create(['email' => 'gone@example.test'])->delete();
        User::factory()->withRole(Role::Cme)->create(['email' => 'actif@example.test']);
        $before = AuditLog::query()->count();

        $this->runJob(SendPasswordLink::forPublicRequest(LinkRequest::Forgot, 'inconnu@example.test'));
        $this->runJob(SendPasswordLink::forPublicRequest(LinkRequest::Forgot, 'off@example.test'));
        $this->runJob(SendPasswordLink::forPublicRequest(LinkRequest::Forgot, 'gone@example.test'));
        // Renvoi d'activation sur un compte déjà activé : rien.
        $this->runJob(SendPasswordLink::forPublicRequest(LinkRequest::ActivationResend, 'actif@example.test'));

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('password_tokens')->count());
        $this->assertSame($before, AuditLog::query()->count());
    }

    public function test_adresse_translitteree_ne_selectionne_jamais_le_voisin(): void
    {
        Notification::fake();
        $latin = User::factory()->withRole(Role::Apprenant)->create(['email' => 'a@example.test']);
        $cyrillic = User::factory()->withRole(Role::Apprenant)->create(['email' => 'а@example.test']);

        $this->runJob(SendPasswordLink::forPublicRequest(LinkRequest::Forgot, 'а@example.test'));

        Notification::assertSentTo($cyrillic, PasswordLinkNotification::class);
        Notification::assertNotSentTo($latin, PasswordLinkNotification::class);
    }

    public function test_echec_de_transport_reprise_avec_nouveau_lien_puis_echec_journalise(): void
    {
        $user = User::factory()->withRole(Role::Apprenant)->create(['email' => 'eleve@example.test']);
        Notification::shouldReceive('sendNow')->andThrow(new RuntimeException('SMTP indisponible'));
        $job = SendPasswordLink::forPublicRequest(LinkRequest::Forgot, 'eleve@example.test');

        $hashes = [];
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $this->runJob($job);
                $this->fail('L’échec de transport doit faire reprendre la tâche.');
            } catch (LinkEmailTransportException $e) {
                $hashes[] = DB::table('password_tokens')->where('user_id', $user->id)->value('token_hash');
                $this->assertStringNotContainsString('#', $e->getMessage());
            }
        }

        // Chaque reprise remplace le lien : un seul valable, le dernier.
        $this->assertNotSame($hashes[0], $hashes[1]);
        $this->assertSame(1, DB::table('password_tokens')->where('user_id', $user->id)->count());
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::PasswordResetLinkSent->value)->count());

        $job->failed($e);
        $failed = AuditLog::query()->where('action', AuditAction::LinkEmailFailed->value)->firstOrFail();
        $this->assertEquals(['lien' => 'réinitialisation', 'origine' => 'demande publique'], $failed->new_values);
        $this->assertSame(AuditLogger::VISITOR_LABEL, $failed->actor_label);

        // Un échec qui n'est pas un échec de remise n'est jamais noté comme tel.
        $job->failed(new RuntimeException('autre'));
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::LinkEmailFailed->value)->count());
    }

    public function test_remise_reussie_puis_journal_en_echec_aucun_second_envoi(): void
    {
        Notification::fake();
        Queue::fake([RecordLinkSent::class]);
        $user = User::factory()->withRole(Role::Apprenant)->create(['email' => 'eleve@example.test']);

        $this->runJob(SendPasswordLink::forPublicRequest(LinkRequest::Forgot, 'eleve@example.test'));

        // L'envoi est terminé (une seule notification) ; le journal est confié à une tâche séparée.
        Notification::assertSentToTimes($user, PasswordLinkNotification::class, 1);
        Queue::assertPushed(RecordLinkSent::class, function (RecordLinkSent $job) use ($user) {
            return $job->userId === $user->id && $job->type === PasswordTokenType::Reset
                && ! str_contains(serialize($job), DB::table('password_tokens')->value('token_hash'));
        });

        // Journal en échec : seule cette tâche est reprise, sans renvoyer d'e-mail.
        $record = new RecordLinkSent($user->id, PasswordTokenType::Reset, LinkRequest::Forgot, null);
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            /**
             * @param  array<string, mixed>  $old
             * @param  array<string, mixed>  $new
             */
            public function record(AuditAction $action, ?Model $subject = null, array $old = [], array $new = [], ?User $actor = null, bool $visitor = false): AuditLog
            {
                throw new RuntimeException('journal indisponible');
            }
        });
        try {
            $record->handle();
            $this->fail('Le journal en échec doit faire reprendre RecordLinkSent.');
        } catch (RuntimeException) {
        }
        Notification::assertSentToTimes($user, PasswordLinkNotification::class, 1);

        $this->app->forgetInstance(AuditLogger::class);
        $record->handle();
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::PasswordResetLinkSent->value)->count());
    }

    public function test_service_administrateur_apres_validation_de_la_transaction_et_auteur_admin(): void
    {
        Notification::fake();
        $admin = User::factory()->withRole(Role::Administrateur)->create();
        $service = app(AccountActivationService::class);

        // Transaction annulée par l'appelant (création T-10 échouée) : aucune tâche, aucun e-mail.
        try {
            DB::transaction(function () use ($service, $admin): void {
                $cme = User::factory()->unactivated()->withRole(Role::Cme)->create(['email' => 'annule@example.test']);
                $service->sendLink($cme, $admin);
                throw new RuntimeException('création annulée');
            });
        } catch (RuntimeException) {
        }
        Notification::assertNothingSent();

        $cme = DB::transaction(function () use ($service, $admin): User {
            $cme = User::factory()->unactivated()->withRole(Role::Cme)->create(['email' => 'nouveau@example.test']);
            $service->sendLink($cme, $admin);

            return $cme;
        });

        Notification::assertSentTo($cme, PasswordLinkNotification::class);
        $log = AuditLog::query()->where('action', AuditAction::ActivationLinkSent->value)->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertEquals(['lien' => 'activation', 'origine' => 'administrateur'], $log->new_values);

        $this->expectException(DomainException::class);
        $service->sendLink(User::factory()->withRole(Role::Entreprise)->create(), $admin);
    }

    public function test_e_mails_en_francais_avec_le_lien_en_fragment(): void
    {
        $user = User::factory()->unactivated()->withRole(Role::Cme)->create(['first_name' => 'Samira']);
        $token = str_repeat('a', 64);

        $activation = (new PasswordLinkNotification(PasswordTokenType::Activation, $token))->toMail($user);
        $reset = (new PasswordLinkNotification(PasswordTokenType::Reset, $token))->toMail($user);

        $this->assertSame('Activez votre compte JobDating', $activation->subject);
        $this->assertSame('http://localhost:5173/activation#'.$token, $activation->actionUrl);
        $this->assertSame('Réinitialisation de votre mot de passe JobDating', $reset->subject);
        $this->assertSame('http://localhost:5173/reinitialiser#'.$token, $reset->actionUrl);
        $this->assertStringContainsString('Samira', (string) $activation->greeting);
    }
}
