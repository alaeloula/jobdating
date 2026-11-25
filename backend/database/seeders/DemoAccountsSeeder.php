<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Learner;
use App\Models\Referentials\Campus;
use App\Models\Referentials\Promotion;
use App\Models\Referentials\SchoolClass;
use App\Models\User;
use App\Services\Events\EventParticipationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Comptes de démonstration fictifs, un par rôle, pour le développement LOCAL uniquement.
 *
 * Ne s'exécute que si APP_ENV=local : aucun compte de démonstration en production
 * (RG-AUTH-05, EXI-SEC-11). Il n'est pas appelé par DatabaseSeeder.
 *
 *     php artisan db:seed --class=DemoAccountsSeeder
 *
 * Identifiants (mot de passe commun : self::PASSWORD) :
 * - admin.demo@jobdating.test      (Administrateur)
 * - cme.demo@jobdating.test        (CME)
 * - direction.demo@jobdating.test  (Direction)
 * - entreprise-demo                (Entreprise, nom d'utilisateur)
 * - apprenant.demo@jobdating.test  (Apprenant)
 *
 * Idempotent : relancé, il remet ces comptes dans leur état initial.
 */
class DemoAccountsSeeder extends Seeder
{
    public const PASSWORD = 'Demo-JobDating-2026';

    /** CNIE FICTIVE de l'apprenant de démonstration (format de App\Support\Cnie). */
    public const DEMO_CNIE = 'DEMO00001';

    /**
     * @var list<array{role: Role, first_name: string, last_name: string, email: ?string, username: ?string}>
     */
    private const ACCOUNTS = [
        ['role' => Role::Administrateur, 'first_name' => 'Admin', 'last_name' => 'Démo', 'email' => 'admin.demo@jobdating.test', 'username' => null],
        ['role' => Role::Cme, 'first_name' => 'Chargé', 'last_name' => 'Démo', 'email' => 'cme.demo@jobdating.test', 'username' => null],
        ['role' => Role::Direction, 'first_name' => 'Direction', 'last_name' => 'Démo', 'email' => 'direction.demo@jobdating.test', 'username' => null],
        ['role' => Role::Entreprise, 'first_name' => 'Entreprise', 'last_name' => 'Démo', 'email' => null, 'username' => 'entreprise-demo'],
        ['role' => Role::Apprenant, 'first_name' => 'Apprenant', 'last_name' => 'Démo', 'email' => 'apprenant.demo@jobdating.test', 'username' => null],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('DemoAccountsSeeder ignoré : réservé à APP_ENV=local.');

            return;
        }

        $this->call(RolesAndPermissionsSeeder::class);

        foreach (self::ACCOUNTS as $account) {
            $key = $account['username'] !== null
                ? ['username' => $account['username']]
                : ['email' => $account['email']];

            $user = User::withTrashed()->firstOrNew($key);
            $user->forceFill([
                'first_name' => $account['first_name'],
                'last_name' => $account['last_name'],
                'email' => $account['email'],
                'username' => $account['username'],
                'password' => self::PASSWORD,
                'activated_at' => $user->activated_at ?? now(),
                'deactivated_at' => null,
                'deleted_at' => null,
            ])->save();

            $user->setRole($account['role']);

            // Fiche de l'entreprise de démonstration (T-11), sans événement : l'administrateur l'ajoute.
            if ($account['role'] === Role::Entreprise && ! Company::query()->where('user_id', $user->getKey())->exists()) {
                Company::query()->forceCreate(['user_id' => $user->getKey(), 'name' => 'Entreprise Démo', 'credentials_version' => 1]);
            }

            if ($account['role'] === Role::Apprenant) {
                $this->learnerRecord($user);
                $this->enrollDemoLearner($user);
            }
        }
    }

    /**
     * Inscription idempotente à l'événement courant, s'il existe (T-14) : un retrait n'est jamais
     * annulé. L'entreprise de démonstration reste à ajouter par l'administrateur.
     */
    private function enrollDemoLearner(User $user): void
    {
        $learnerId = Learner::query()->where('user_id', $user->getKey())->value('id');
        if ($learnerId === null) {
            return;
        }

        $participation = app(EventParticipationService::class);
        DB::transaction(fn () => $participation->enroll($participation->beginActivation(), [(int) $learnerId]));
    }

    /**
     * Fiche de l'apprenant de démonstration (T-12), sans événement (inscription : T-14). Elle exige
     * un campus, une promotion et une classe ACTIFS : sans eux, elle est ignorée avec un message
     * (aucune valeur officielle n'est créée implicitement, plan T-12 v2).
     */
    private function learnerRecord(User $user): void
    {
        if (Learner::query()->where('user_id', $user->getKey())->exists()) {
            return;
        }

        $campus = Campus::query()->where('active', true)->orderBy('id')->first();
        $promotion = Promotion::query()->where('active', true)->orderBy('id')->first();
        $class = SchoolClass::query()->where('active', true)->orderBy('id')->first();
        if ($campus === null || $promotion === null || $class === null) {
            $this->command?->warn('Fiche de l’apprenant de démonstration ignorée : ajoutez d’abord un campus, une promotion et une classe actifs (Référentiels).');

            return;
        }
        if (Learner::query()->where('cnie', self::DEMO_CNIE)->exists()) {
            $this->command?->warn('Fiche de l’apprenant de démonstration ignorée : la CNIE fictive est déjà utilisée.');

            return;
        }

        Learner::query()->forceCreate([
            'user_id' => $user->getKey(),
            'cnie' => self::DEMO_CNIE,
            'campus_id' => $campus->id,
            'promotion_id' => $promotion->id,
            'class_id' => $class->id,
        ]);
    }
}
