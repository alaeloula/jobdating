<?php

namespace App\Services\Learners;

use App\Enums\AuditAction;
use App\Enums\PasswordTokenType;
use App\Enums\Role;
use App\Models\Learner;
use App\Models\Referentials\Campus;
use App\Models\Referentials\Competence;
use App\Models\Referentials\Promotion;
use App\Models\Referentials\ReferentialModel;
use App\Models\Referentials\SchoolClass;
use App\Models\User;
use App\Notifications\GeneratedPasswordNotification;
use App\Services\Accounts\AccountRuleException;
use App\Services\AuditLogger;
use App\Services\Auth\SessionRevoker;
use App\Services\Passwords\PasswordAccess;
use App\Services\Passwords\PasswordGenerator;
use App\Services\Passwords\PasswordTokenService;
use App\Support\Cnie;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Apprenants (ADM-03, T-12). Toutes les écritures en transaction, rejouées en cas d'interblocage.
 *
 * ORDRE DE VERROUILLAGE (contrat T-02, plan T-12 §3), à respecter aussi par T-13 :
 *   1. les CLASSES concernées, par identifiant croissant (`ClassUsage`, changement de filière) ;
 *   2. le COMPTE `users` ;
 *   3. la FICHE `learners`.
 * Si la classe d'un apprenant a changé pendant l'attente, l'opération recommence avec les bonnes
 * classes : aucun verrou de classe n'est jamais pris après celui du compte.
 *
 * L'état du compte (désactivé, supprimé) est toujours relu sous le verrou du compte.
 */
class LearnerService
{
    private const ATTEMPTS = 3;

    /** Nombre maximal d'apprenants concernés par une action en masse (H2). */
    public const BULK_LIMIT = 2000;

    public const ACTION_DEACTIVATE = 'deactivate';

    public const ACTION_ADD_COMPETENCE = 'add_competence';

    public function __construct(
        private readonly SessionRevoker $sessions,
        private readonly PasswordTokenService $tokens,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------ Fiche

    /**
     * Création à la main : compte apprenant activé, mot de passe généré envoyé par e-mail après la
     * validation finale et renvoyé une fois (décision du 19/11). Aucun changement imposé (14/11).
     *
     * @param  array{cnie: string, first_name: string, last_name: string, email: string, phone?: string|null, campus_id: int, promotion_id: int, class_id: int, cme_learner_id?: string|null}  $data
     * @return array{learner: Learner, password: string}
     */
    public function create(array $data, User $admin): array
    {
        $password = PasswordGenerator::generate();
        $cnie = $this->cnie($data['cnie']);

        $learner = $this->unique(fn () => DB::transaction(function () use ($data, $cnie, $password, $admin): Learner {
            $this->lockClasses([(int) $data['class_id']]);
            $this->assertActive(Campus::class, $data['campus_id'], 'campus_id', 'Ce campus n’est plus proposé.');
            $this->assertActive(Promotion::class, $data['promotion_id'], 'promotion_id', 'Cette promotion n’est plus proposée.');
            $this->assertActive(SchoolClass::class, $data['class_id'], 'class_id', 'Cette classe n’est plus proposée.');
            $this->assertCnieFree($cnie, null);

            return $this->insert(['cnie' => $cnie] + $data, $password, $password, true, $admin);
        }, self::ATTEMPTS));

        return ['learner' => $learner, 'password' => $password];
    }

    /**
     * Modification de toutes les informations (ADM-03, étape 2). Une valeur de référentiel déjà
     * associée peut rester même inactive ; une NOUVELLE valeur doit être active. Si l'e-mail change,
     * les liens en attente sont annulés (plan T-12 v2, constat 3).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Learner $learner, array $data, User $admin): Learner
    {
        if (array_key_exists('cnie', $data)) {
            $data['cnie'] = $this->cnie((string) $data['cnie']);
        }

        for ($attempt = 1; ; $attempt++) {
            $currentClass = (int) Learner::query()->whereKey($learner->getKey())->value('class_id');
            $classes = array_unique(array_filter([$currentClass, isset($data['class_id']) ? (int) $data['class_id'] : null]));

            try {
                return $this->unique(fn () => DB::transaction(function () use ($learner, $data, $admin, $currentClass, $classes): Learner {
                    $this->lockClasses($classes);
                    $user = $this->lockUser($learner);
                    $locked = $this->lockLearner($learner);
                    if ($locked->class_id !== $currentClass) {
                        throw new ClassMovedException;
                    }

                    $fields = array_intersect_key($data, array_flip(Learner::EDITABLE));
                    foreach ([[Campus::class, 'campus_id', 'Ce campus n’est plus proposé.'], [Promotion::class, 'promotion_id', 'Cette promotion n’est plus proposée.'], [SchoolClass::class, 'class_id', 'Cette classe n’est plus proposée.']] as [$model, $field, $message]) {
                        if (array_key_exists($field, $fields) && (int) $fields[$field] !== $locked->{$field}) {
                            $this->assertActive($model, $fields[$field], $field, $message);
                        }
                    }
                    if (array_key_exists('cnie', $fields) && $fields['cnie'] !== $locked->cnie) {
                        $this->assertCnieFree($fields['cnie'], $locked->getKey());
                    }

                    $this->apply($locked, $user, $data, $admin);

                    return $locked->setRelation('user', $user);
                }, self::ATTEMPTS));
            } catch (ClassMovedException $e) {
                if ($attempt >= self::ATTEMPTS) {
                    throw new RuntimeException('La fiche a été modifiée en même temps : réessayez.', 0, $e);
                }
            }
        }
    }

    /**
     * Réinitialisation par l'administrateur (ADM-03 étape 3, décision du 14/11) : affiché une fois,
     * envoyé par e-mail, liens annulés, toutes les sessions fermées.
     */
    public function resetPassword(Learner $learner, User $admin): string
    {
        $password = PasswordGenerator::generate();

        DB::transaction(function () use ($learner, $admin, $password): void {
            $user = $this->lockUser($learner);
            if (! PasswordAccess::canReset($user)) {
                throw new AccountRuleException('not_resettable', 'Ce compte est désactivé : réactivez-le d’abord.');
            }

            User::withoutAccountAudit(fn () => $user->forceFill(['password' => $password])->save());
            $this->tokens->revokeAll($user, PasswordTokenType::Reset);
            $this->sessions->closeAll($user);
            $this->audit->record(AuditAction::PasswordReset, $user, new: ['note' => 'Réinitialisé par l’administrateur'], actor: $admin);
            $user->notify(new GeneratedPasswordNotification($password, GeneratedPasswordNotification::REASON_RESET));
        }, self::ATTEMPTS);

        return $password;
    }

    public function deactivate(Learner $learner): Learner
    {
        return DB::transaction(function () use ($learner): Learner {
            $user = $this->lockUser($learner);
            if (! $user->isDeactivated()) {
                $user->forceFill(['deactivated_at' => now()])->save();
            }
            $this->cut($user);

            return $learner->setRelation('user', $user);
        }, self::ATTEMPTS);
    }

    public function reactivate(Learner $learner): Learner
    {
        return DB::transaction(function () use ($learner): Learner {
            $user = $this->lockUser($learner);
            if ($user->isDeactivated()) {
                $user->forceFill(['deactivated_at' => null])->save();
            }

            return $learner->setRelation('user', $user);
        }, self::ATTEMPTS);
    }

    /** Suppression logique (décision du 19/11) : la fiche et ses associations restent. */
    public function delete(Learner $learner): void
    {
        DB::transaction(function () use ($learner): void {
            $user = $this->lockUser($learner);
            $user->delete();
            $this->cut($user);
        }, self::ATTEMPTS);
    }

    /** Restauration : l'état de désactivation antérieur est conservé ; aucun lien n'est recréé. */
    public function restore(Learner $learner): Learner
    {
        return DB::transaction(function () use ($learner): Learner {
            $user = $this->lockUser($learner, allowDeleted: true);
            if ($user->trashed()) {
                $user->restore();
            }

            return $learner->setRelation('user', $user);
        }, self::ATTEMPTS);
    }

    /**
     * Compétences d'un apprenant (décision du 16/11) : la liste reçue est la sélection complète.
     * Une compétence NOUVELLE doit être active ; une association devenue inactive peut rester.
     *
     * @param  list<int>  $competenceIds
     */
    public function updateCompetences(Learner $learner, array $competenceIds, User $actor): Learner
    {
        return DB::transaction(function () use ($learner, $competenceIds, $actor): Learner {
            $user = $this->lockUser($learner);
            $locked = $this->lockLearner($learner)->setRelation('user', $user);
            $before = $this->competenceLabels($locked);
            $current = $locked->competences()->pluck('competences.id')->map(fn ($id) => (int) $id)->all();

            $new = array_values(array_diff($competenceIds, $current));
            if ($new !== [] && Competence::query()->whereIn('id', $new)->where('active', true)->count() !== count($new)) {
                throw ValidationException::withMessages(['competence_ids' => 'Une compétence choisie n’est plus proposée.']);
            }

            $locked->competences()->sync(array_fill_keys($competenceIds, ['created_at' => now()]));
            $after = $this->competenceLabels($locked);
            if ($before !== $after) {
                $this->audit->record(AuditAction::LearnerCompetencesUpdated, $locked, ['competences' => $before], ['competences' => $after], actor: $actor);
            }

            return $locked;
        }, self::ATTEMPTS);
    }

    // ------------------------------------------------------------------ Import (T-13)

    /**
     * Création d'un apprenant par l'import (plan T-13 v2.1, §4) : l'APPELANT a ouvert la transaction
     * et pris les verrous (classes, comptes, fiches) et a vérifié les règles. Le mot de passe est
     * préparé avant la transaction : `$hash` est enregistré tel quel (aucun hachage sous les verrous) ;
     * l'e-mail de création n'est programmé que pour un compte ACTIF (décision 7 du 20/11).
     *
     * @param  array{cnie: string, first_name: string, last_name: string, email: string, phone: string|null, campus_id: int, promotion_id: int, class_id: int}  $data
     */
    public function importCreate(array $data, string $password, string $hash, bool $active, User $admin): Learner
    {
        return $this->insert($data, $hash, $active ? $password : null, $active, $admin);
    }

    /**
     * Mise à jour d'un apprenant connu par l'import : prénom, nom, e-mail, téléphone, campus,
     * promotion, classe et statut. Mot de passe, compétences, passages et CV inchangés. L'APPELANT
     * détient les verrous et a relu `$locked` et `$user` sous ces verrous.
     *
     * @param  array{first_name: string, last_name: string, email: string, phone: string|null, campus_id: int, promotion_id: int, class_id: int}  $data
     */
    public function importUpdate(Learner $locked, User $user, array $data, bool $active, User $admin): void
    {
        $this->apply($locked, $user, $data, $admin);

        if (! $active && ! $user->isDeactivated()) {
            $user->forceFill(['deactivated_at' => now()])->save();
            $this->cut($user);
        } elseif ($active && $user->isDeactivated()) {
            $user->forceFill(['deactivated_at' => null])->save();
        }
    }

    /**
     * Écriture d'un nouvel apprenant (création à la main et import). `$passwordValue` est le mot de
     * passe en clair (haché par le cast) ou un hachage déjà calculé (conservé tel quel par le cast).
     * `$notify` : mot de passe à envoyer par e-mail, ou null pour ne rien envoyer.
     *
     * @param  array<string, mixed>  $data
     */
    private function insert(array $data, string $passwordValue, ?string $notify, bool $active, User $admin): Learner
    {
        $user = new User;
        $user->forceFill([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $passwordValue,
            'activated_at' => now(),
            'deactivated_at' => $active ? null : now(),
        ])->save();
        $user->setRole(Role::Apprenant);

        $learner = new Learner;
        $learner->forceFill([
            'user_id' => $user->getKey(),
            'cnie' => $data['cnie'],
            'campus_id' => (int) $data['campus_id'],
            'promotion_id' => (int) $data['promotion_id'],
            'class_id' => (int) $data['class_id'],
            'cme_learner_id' => $data['cme_learner_id'] ?? null,
        ])->save();
        $learner->setRelation('user', $user);

        $this->audit->record(AuditAction::LearnerCreated, $learner, new: $this->snapshot($learner), actor: $admin);
        if ($notify !== null) {
            // Mise en file après la validation FINALE (afterCommit) : une annulation n'envoie rien.
            $user->notify(new GeneratedPasswordNotification($notify, GeneratedPasswordNotification::REASON_CREATED));
        }

        return $learner;
    }

    /**
     * Écriture des champs d'un apprenant existant (modification à la main et import) ; journal des
     * seuls champs modifiés ; liens annulés si l'e-mail change (plan T-12 v2, constat 3).
     *
     * @param  array<string, mixed>  $data
     */
    private function apply(Learner $locked, User $user, array $data, User $admin): void
    {
        $fields = array_intersect_key($data, array_flip(Learner::EDITABLE));
        $identity = array_intersect_key($data, array_flip(['first_name', 'last_name', 'email', 'phone']));
        $user->fill($identity);
        $emailChanged = $user->isDirty('email');
        $user->save();
        if ($emailChanged) {
            // Un lien envoyé à l'ancienne adresse ne doit plus permettre de changer le mot de passe.
            $this->tokens->revokeAll($user, PasswordTokenType::Reset);
            $this->tokens->revokeAll($user, PasswordTokenType::Activation);
        }

        $locked->setRelation('user', $user);
        $before = $this->snapshot($locked);
        $locked->fill($fields);
        $changed = array_keys($locked->getDirty());
        $locked->save();

        if ($changed !== []) {
            $after = $this->snapshot($locked->refresh()->setRelation('user', $user));
            $keys = $this->snapshotKeys($changed);
            $this->audit->record(
                AuditAction::LearnerUpdated,
                $locked,
                array_intersect_key($before, array_flip($keys)),
                array_intersect_key($after, array_flip($keys)),
                actor: $admin,
            );
        }
    }

    // ------------------------------------------------------------------ Actions en masse (§5)

    /**
     * Sélection d'un écran : identifiants (visibles de l'acteur) ou filtres de la liste.
     *
     * @param  list<int>|null  $ids
     * @return Builder<Learner>
     */
    public function selection(?array $ids, LearnerFilters $filters): Builder
    {
        if ($ids === null) {
            return $filters->query();
        }

        // Même visibilité que la liste : pour la CME, un apprenant supprimé est invisible (422),
        // comme un identifiant inexistant ; l'administrateur le voit, sans qu'il soit jamais concerné.
        $base = $filters->visible();
        $found = (clone $base)->whereIn('learners.id', $ids)->count();
        if ($found !== count($ids)) {
            throw ValidationException::withMessages(['ids' => 'Un apprenant sélectionné est introuvable.']);
        }

        return $base->whereIn('learners.id', $ids);
    }

    /**
     * Apprenants concernés par une action, parmi la sélection (même règle pour le comptage et
     * l'exécution).
     *
     * @param  Builder<Learner>  $selection
     * @return Builder<Learner>
     */
    public function eligible(Builder $selection, string $action, ?int $competenceId): Builder
    {
        $query = (clone $selection)->whereNull('users.deleted_at');

        return match ($action) {
            self::ACTION_DEACTIVATE => $query->whereNull('users.deactivated_at'),
            self::ACTION_ADD_COMPETENCE => $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('learner_competence')
                ->whereColumn('learner_competence.learner_id', 'learners.id')
                ->where('learner_competence.competence_id', (int) $competenceId)),
            default => throw new RuntimeException('Action inconnue.'),
        };
    }

    /**
     * Désactivation en masse (ADM-03, étape 5). Comptes concernés relus et verrouillés dans l'ordre
     * des identifiants ; nombre comparé à celui confirmé AVANT toute écriture.
     *
     * @param  Builder<Learner>  $selection
     * @param  array<string, string>  $description  sélection décrite pour le journal, sans recherche libre
     */
    public function bulkDeactivate(Builder $selection, int $expected, array $description, User $admin): int
    {
        return DB::transaction(function () use ($selection, $expected, $description, $admin): int {
            $userIds = $this->eligible($selection, self::ACTION_DEACTIVATE, null)->pluck('learners.user_id');
            $users = User::query()->whereIn('id', $userIds)->whereNull('deactivated_at')
                ->orderBy('id')->lockForUpdate()->get();

            if (! $this->confirmCount($users->count(), $expected)) {
                return 0;
            }

            foreach ($users as $user) {
                $user->forceFill(['deactivated_at' => now()])->save();
                $this->cut($user);
            }
            $this->audit->record(AuditAction::LearnerBulkDeactivated, null, new: [
                'nombre' => $users->count(),
                'selection' => $description,
            ], actor: $admin);

            return $users->count();
        }, self::ATTEMPTS);
    }

    /**
     * Ajout d'une compétence à une sélection (décision du 19/11, COMP-01) : apprenants non
     * supprimés, actifs ou désactivés, qui ne l'ont pas encore. Idempotent.
     *
     * Verrous (revue Codex T-12, constat 2) : COMPTES d'abord (par identifiant croissant), puis
     * FICHES ; l'éligibilité est relue sous ces verrous (un compte supprimé entre-temps n'est plus
     * concerné, et le nombre confirmé est comparé à cet état relu).
     *
     * @param  Builder<Learner>  $selection
     * @param  array<string, string>  $description  sélection décrite pour le journal, sans recherche libre
     */
    public function bulkAddCompetence(Builder $selection, int $expected, int $competenceId, array $description, User $actor): int
    {
        return DB::transaction(function () use ($selection, $expected, $competenceId, $description, $actor): int {
            // Verrou partagé : compatible avec l'insertion d'une association ailleurs (contrôle de clé
            // étrangère), mais une désactivation simultanée de la compétence attend.
            $competence = Competence::query()->sharedLock()->find($competenceId);
            if ($competence === null || ! $competence->active) {
                throw ValidationException::withMessages(['competence_id' => 'Cette compétence n’est plus proposée.']);
            }

            $candidates = (clone $selection)->pluck('learners.user_id');
            // Lecture verrouillée (état courant, pas l'instantané de la transaction) : comptes supprimés exclus.
            $userIds = User::query()->whereIn('id', $candidates)->orderBy('id')->lockForUpdate()->pluck('id');
            $learners = Learner::query()
                ->whereIn('user_id', $userIds)
                ->whereIn('id', (clone $selection)->select('learners.id'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('learner_competence')
                    ->whereColumn('learner_competence.learner_id', 'learners.id')
                    ->where('learner_competence.competence_id', $competenceId))
                ->with(['user', 'competences'])
                ->orderBy('id')->lockForUpdate()->get();

            // Associations relues en lecture VERROUILLÉE (état courant, pas l'instantané de la
            // transaction) : une compétence ajoutée entre-temps par une autre opération est vue, et le
            // nombre confirmé est recompté (409) au lieu d'une erreur d'unicité (contre-revue T-12, constat 6).
            $already = DB::table('learner_competence')
                ->whereIn('learner_id', $learners->modelKeys())
                ->where('competence_id', $competenceId)
                ->lockForUpdate()
                ->pluck('learner_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $learners = $learners->reject(fn (Learner $l) => in_array($l->id, $already, true))->values();

            if (! $this->confirmCount($learners->count(), $expected)) {
                return 0;
            }

            foreach ($learners as $learner) {
                $before = $this->competenceLabels($learner);
                $learner->competences()->attach($competenceId, ['created_at' => now()]);
                $this->audit->record(AuditAction::LearnerCompetencesUpdated, $learner, ['competences' => $before], ['competences' => [...$before, $competence->label]], actor: $actor);
            }
            $this->audit->record(AuditAction::LearnerBulkCompetenceAdded, null, new: [
                'competence' => $competence->label,
                'nombre' => $learners->count(),
                'selection' => $description,
            ], actor: $actor);

            return $learners->count();
        }, self::ATTEMPTS);
    }

    // ------------------------------------------------------------------ Outils

    /** CNIE normalisée et valide (décision 9), sinon 422 sans répéter la valeur. */
    private function cnie(string $value): string
    {
        $cnie = Cnie::normalize($value);
        if (! Cnie::isValid($cnie)) {
            throw ValidationException::withMessages(['cnie' => Cnie::MESSAGE]);
        }

        return $cnie;
    }

    private function assertCnieFree(string $cnie, ?int $exceptLearnerId): void
    {
        $existing = Learner::query()->with('user')->where('cnie', $cnie)
            ->when($exceptLearnerId !== null, fn (Builder $q) => $q->whereKeyNot($exceptLearnerId))->first();
        if ($existing !== null) {
            throw ValidationException::withMessages(['cnie' => $existing->user->trashed()
                ? 'Cette CNIE appartient à une fiche supprimée : restaurez-la plutôt.'
                : 'Cette CNIE est déjà enregistrée : modifiez plutôt la fiche existante.']);
        }
    }

    /**
     * Collision d'unicité au dernier moment (deux saisies simultanées) : 422 sur le bon champ, sans
     * jamais renvoyer la valeur ni le message SQL (qui contiendrait la CNIE).
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function unique(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (UniqueConstraintViolationException $e) {
            $message = $e->getMessage();
            $field = match (true) {
                str_contains($message, 'learners_cnie_unique') => ['cnie', 'Cette CNIE vient d’être enregistrée pour un autre apprenant.'],
                str_contains($message, 'learners_cme_learner_id_unique') => ['cme_learner_id', 'Cet identifiant CME est déjà utilisé.'],
                str_contains($message, 'users_email_unique') => ['email', 'Cet e-mail est déjà utilisé par un autre compte.'],
                default => ['cnie', 'Une valeur unique est déjà utilisée par un autre apprenant.'],
            };
            throw ValidationException::withMessages([$field[0] => $field[1]]);
        }
    }

    /** Vrai : écrire. Faux : 0 attendu et 0 trouvé (aucune écriture). Sinon 409 ou 422. */
    private function confirmCount(int $found, int $expected): bool
    {
        if ($found > self::BULK_LIMIT) {
            throw ValidationException::withMessages(['selection' => 'Plus de '.self::BULK_LIMIT.' apprenants concernés : affinez le filtre.']);
        }
        if ($found !== $expected) {
            throw new SelectionChangedException($found, $expected);
        }

        return $found > 0;
    }

    /** @param list<int> $classIds */
    private function lockClasses(array $classIds): void
    {
        $classIds = array_values(array_unique(array_filter($classIds)));
        sort($classIds);
        if ($classIds !== []) {
            SchoolClass::query()->whereIn('id', $classIds)->orderBy('id')->lockForUpdate()->get();
        }
    }

    private function lockUser(Learner $learner, bool $allowDeleted = false): User
    {
        $user = User::withTrashed()->whereKey($learner->user_id)->lockForUpdate()->firstOrFail();
        if ($user->trashed() && ! $allowDeleted) {
            throw AccountRuleException::deleted();
        }

        return $user;
    }

    private function lockLearner(Learner $learner): Learner
    {
        return Learner::query()->whereKey($learner->getKey())->lockForUpdate()->firstOrFail();
    }

    /** Coupe l'accès : sessions fermées (RG-APR-05) et liens annulés. */
    private function cut(User $user): void
    {
        $this->sessions->closeAll($user);
        $this->tokens->revokeAll($user, PasswordTokenType::Reset);
        $this->tokens->revokeAll($user, PasswordTokenType::Activation);
    }

    /**
     * @param  class-string<ReferentialModel>  $model
     */
    private function assertActive(string $model, mixed $id, string $field, string $message): void
    {
        if (! $model::query()->whereKey((int) $id)->where('active', true)->exists()) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    /** @return list<string> */
    private function competenceLabels(Learner $learner): array
    {
        $labels = $learner->competences()->pluck('label')->map(fn ($l) => (string) $l)->all();
        sort($labels);

        return $labels;
    }

    /**
     * Valeurs de la fiche pour le journal : la CNIE sous la clé `cnie` (masquée par AuditLogger),
     * les référentiels par leur libellé.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Learner $learner): array
    {
        return [
            'cnie' => $learner->cnie,
            'campus' => Campus::query()->find($learner->campus_id)?->displayLabel(),
            'promotion' => Promotion::query()->find($learner->promotion_id)?->displayLabel(),
            'classe' => SchoolClass::query()->find($learner->class_id)?->displayLabel(),
            'identifiant_cme' => $learner->cme_learner_id,
        ];
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function snapshotKeys(array $fields): array
    {
        $map = ['cnie' => 'cnie', 'campus_id' => 'campus', 'promotion_id' => 'promotion', 'class_id' => 'classe', 'cme_learner_id' => 'identifiant_cme'];

        return array_values(array_unique(array_map(fn (string $f) => $map[$f] ?? $f, $fields)));
    }
}
