<?php

namespace App\Services\Companies;

use App\Enums\AuditAction;
use App\Enums\PasswordTokenType;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Event;
use App\Models\Referentials\Competence;
use App\Models\Referentials\Filiere;
use App\Models\Referentials\ReferentialModel;
use App\Models\Referentials\Sector;
use App\Models\User;
use App\Services\Accounts\AccountRuleException;
use App\Services\AuditLogger;
use App\Services\Auth\SessionRevoker;
use App\Services\Events\EventParticipationService;
use App\Services\Passwords\PasswordGenerator;
use App\Services\Passwords\PasswordTokenService;
use App\Support\CompanyNameKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Entreprises (ADM-02, RG-ENTR-01 à 08, T-11, plan v2).
 *
 * - Création : compte entreprise ACTIVÉ (aucun lien d'activation, aucun e-mail, RG-ENTR-06),
 *   nom d'utilisateur choisi, mot de passe généré renvoyé une seule fois avec la version de la fiche.
 * - Logo : validé avant tout effet, stocké avant la transaction, supprimé si elle échoue ;
 *   l'ancien n'est supprimé qu'après validation.
 * - Réinitialisation : toutes les sessions fermées (décision du 17/11), liens annulés, aucun
 *   e-mail, nouvelle version de fiche.
 */
class CompanyService
{
    public const LOGO_DISK = 'local';

    public const LOGO_DIR = 'logos';

    private const ATTEMPTS = 3;

    public function __construct(
        private readonly SessionRevoker $sessions,
        private readonly PasswordTokenService $tokens,
        private readonly AuditLogger $audit,
        private readonly EventParticipationService $participation,
    ) {}

    /**
     * Création complète dans UNE transaction (plan T-14, § 5) : événement verrouillé et contrôlé, compte,
     * fiche, participation et journal. Toute erreur supprime le nouveau logo. `$placement` est absent
     * seulement pour une reprise (T-16) : l'API l'exige toujours (RG-ENTR-03).
     *
     * @param  array<string, mixed>  $data  champs de la fiche + username
     * @param  array{event_id: int, floor?: string|null, table_label?: string|null}|null  $placement
     * @return array{company: Company, password: string}
     */
    public function create(array $data, ?UploadedFile $logo, User $admin, ?array $placement = null): array
    {
        $password = PasswordGenerator::generate();
        $logoPath = $logo !== null ? $this->storeLogo($logo) : null;
        $discard = $this->discardOnce($logoPath);

        try {
            // Annulation par un appelant qui englobe cette création : le nouveau fichier est supprimé
            // (revue Codex T-11, constat 1). Une erreur interne est traitée par le catch ci-dessous.
            $this->discardIfEnclosingRollsBack($discard);
            $company = DB::transaction(function () use ($data, $logoPath, $password, $admin, $placement): Company {
                // Ordre (plan T-64 v1.3, § 4) : noms → événement → compte → entreprise.
                CompanyNameLock::acquire();
                $event = $placement !== null ? $this->participation->lockEligibleEvent($placement['event_id']) : null;
                $this->assertActive(Sector::class, $data['sector_id'] ?? null, 'sector_id', 'Ce secteur n’est plus proposé.');

                $user = new User;
                $user->forceFill([
                    // Identité affichée du compte : nom de l'entreprise (plan v2, constat 5).
                    'first_name' => mb_substr((string) $data['name'], 0, 100),
                    'last_name' => '',
                    'username' => $data['username'],
                    'email' => null,
                    'password' => $password,
                    'activated_at' => now(),
                ])->save();
                $user->setRole(Role::Entreprise);

                $company = new Company;
                $company->fill(array_intersect_key($data, array_flip(Company::EDITABLE)));
                $company->forceFill([
                    'user_id' => $user->getKey(),
                    'name_key' => CompanyNameKey::of((string) $company->name),
                    'logo_path' => $logoPath,
                    'credentials_version' => 1,
                    'credentials_printed_at' => null,
                ])->save();

                $this->audit->record(AuditAction::CompanyCreated, $company, new: $this->snapshot($company) + ['nom_utilisateur' => $user->username], actor: $admin);
                if ($event !== null) {
                    $this->participation->attachNewCompany($event, $company, $placement['floor'] ?? null, $placement['table_label'] ?? null, $admin);
                }

                return $company;
            }, self::ATTEMPTS);
        } catch (UniqueConstraintViolationException $e) {
            $discard();
            throw ValidationException::withMessages([
                'username' => 'Ce nom d’utilisateur vient d’être pris. Proposition : '.UsernameSuggester::suggest((string) $data['name']).'.',
            ]);
        } catch (Throwable $e) {
            $discard();
            throw $e;
        }

        return ['company' => $company, 'password' => $password];
    }

    /**
     * Création par l'import (plan T-64 v1.3, § 6) : l'APPELANT a ouvert la transaction, pris le verrou
     * des noms puis celui de l'événement, et revalidé la ligne. `$hash` est enregistré tel quel (aucun
     * hachage sous les verrous) ; aucun logo, aucun e-mail (décision du 14/11).
     */
    public function importCreate(Event $event, string $name, string $username, string $hash, User $admin): Company
    {
        $user = new User;
        $user->forceFill([
            'first_name' => mb_substr($name, 0, 100),
            'last_name' => '',
            'username' => $username,
            'email' => null,
            'password' => $hash,
            'activated_at' => now(),
        ])->save();
        $user->setRole(Role::Entreprise);

        $company = new Company;
        $company->forceFill([
            'name' => $name,
            'name_key' => CompanyNameKey::of($name),
            'user_id' => $user->getKey(),
            'logo_path' => null,
            'credentials_version' => 1,
            'credentials_printed_at' => null,
        ])->save();

        $this->audit->record(AuditAction::CompanyCreated, $company, new: $this->snapshot($company) + ['nom_utilisateur' => $username, 'source' => 'import'], actor: $admin);
        $this->participation->attachNewCompany($event, $company, null, null, $admin);

        return $company;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, array $data, User $admin): Company
    {
        return DB::transaction(function () use ($company, $data, $admin): Company {
            // Renommage : verrou des noms D'ABORD, puis l'ordre existant entreprise → compte (plan T-64
            // v1.3, § 4 ; contre-revue, constat 2).
            if (array_key_exists('name', $data)) {
                CompanyNameLock::acquire();
            }
            $locked = $this->lock($company);
            $fields = array_intersect_key($data, array_flip(Company::EDITABLE));

            // Un secteur devenu inactif peut être conservé ; un NOUVEAU secteur doit être actif.
            if (array_key_exists('sector_id', $fields) && $fields['sector_id'] !== $locked->sector_id) {
                $this->assertActive(Sector::class, $fields['sector_id'], 'sector_id', 'Ce secteur n’est plus proposé.');
            }

            $before = $this->snapshot($locked);
            $locked->fill($fields);
            if ($locked->isDirty('name')) {
                $locked->forceFill(['name_key' => CompanyNameKey::of($locked->name)]);
            }
            $changed = array_values(array_diff(array_keys($locked->getDirty()), ['name_key']));
            $locked->save();

            if (in_array('name', $changed, true)) {
                $user = $locked->user;
                User::withoutAccountAudit(fn () => $user->forceFill(['first_name' => mb_substr($locked->name, 0, 100)])->save());
            }

            if ($changed !== []) {
                $after = $this->snapshot($locked);
                $this->audit->record(
                    AuditAction::CompanyUpdated,
                    $locked,
                    array_intersect_key($before, array_flip($this->snapshotKeys($changed))),
                    array_intersect_key($after, array_flip($this->snapshotKeys($changed))),
                    actor: $admin,
                );
            }

            return $locked;
        }, self::ATTEMPTS);
    }

    /**
     * Description et site web, par l'entreprise elle-même (ENT-08, décision du 19/11).
     *
     * @param  array<string, mixed>  $data
     */
    public function updateOwn(Company $company, array $data, User $actor): Company
    {
        return $this->update($company, array_intersect_key($data, array_flip(['description', 'website'])), $actor);
    }

    public function replaceLogo(Company $company, UploadedFile $logo, User $admin): Company
    {
        $path = $this->storeLogo($logo);
        $discard = $this->discardOnce($path);

        $this->discardIfEnclosingRollsBack($discard);

        try {
            return DB::transaction(function () use ($company, $path, $admin): Company {
                $locked = $this->lock($company);
                $old = $locked->logo_path;
                $locked->forceFill(['logo_path' => $path])->save();
                $this->audit->record(AuditAction::CompanyUpdated, $locked, new: ['logo' => $old === null ? 'ajouté' : 'remplacé'], actor: $admin);
                // L'ancien fichier n'est supprimé qu'après la validation FINALE (transaction englobante
                // comprise) : une annulation laisse la fiche pointer vers un fichier existant.
                $this->discardOnCommit($old);

                return $locked;
            }, self::ATTEMPTS);
        } catch (Throwable $e) {
            // Échec de cette opération (transaction annulée ou impossible à ouvrir) : rien n'a été enregistré.
            $discard();
            throw $e;
        }
    }

    public function removeLogo(Company $company, User $admin): Company
    {
        return DB::transaction(function () use ($company, $admin): Company {
            $locked = $this->lock($company);
            $old = $locked->logo_path;
            if ($old !== null) {
                $locked->forceFill(['logo_path' => null])->save();
                $this->audit->record(AuditAction::CompanyUpdated, $locked, new: ['logo' => 'supprimé'], actor: $admin);
                $this->discardOnCommit($old);
            }

            return $locked;
        }, self::ATTEMPTS);
    }

    public function deactivate(Company $company): Company
    {
        return DB::transaction(function () use ($company): Company {
            $locked = $this->lock($company);
            $user = User::withTrashed()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
            if (! $user->isDeactivated()) {
                $user->forceFill(['deactivated_at' => now()])->save();
            }
            $this->sessions->closeAll($user);
            $this->tokens->revokeAll($user, PasswordTokenType::Reset);

            return $locked;
        }, self::ATTEMPTS);
    }

    public function reactivate(Company $company): Company
    {
        return DB::transaction(function () use ($company): Company {
            $locked = $this->lock($company);
            $user = User::withTrashed()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
            if ($user->isDeactivated()) {
                $user->forceFill(['deactivated_at' => null])->save();
            }

            return $locked;
        }, self::ATTEMPTS);
    }

    /**
     * Réinitialisation par l'administrateur (RG-ENTR-07) : aucun e-mail, toutes les sessions fermées.
     *
     * @return array{company: Company, password: string}
     */
    public function resetPassword(Company $company, User $admin): array
    {
        $password = PasswordGenerator::generate();

        $locked = DB::transaction(function () use ($company, $admin, $password): Company {
            $locked = $this->lock($company);
            $user = User::withTrashed()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();

            if ($user->isDeactivated() || $user->trashed()) {
                throw new AccountRuleException('not_resettable', 'Cette entreprise est désactivée : réactivez-la d’abord.');
            }

            User::withoutAccountAudit(fn () => $user->forceFill(['password' => $password])->save());
            $this->tokens->revokeAll($user, PasswordTokenType::Reset);
            $this->sessions->closeAll($user);
            $locked->forceFill([
                'credentials_version' => $locked->credentials_version + 1,
                'credentials_printed_at' => null,
            ])->save();
            $this->audit->record(AuditAction::PasswordReset, $user, new: ['note' => 'Réinitialisé par l’administrateur'], actor: $admin);

            return $locked;
        }, self::ATTEMPTS);

        return ['company' => $locked, 'password' => $password];
    }

    /**
     * Confirmation « J'ai imprimé la fiche » (RG-ENTR-07, décision 8 du 19/11) : seulement pour la
     * version courante des identifiants ; idempotente.
     */
    public function confirmPrinted(Company $company, int $version, User $admin): Company
    {
        return DB::transaction(function () use ($company, $version, $admin): Company {
            $locked = $this->lock($company);

            if ($version !== $locked->credentials_version) {
                throw new StaleCredentialsException;
            }

            if ($locked->credentials_printed_at === null) {
                $locked->forceFill(['credentials_printed_at' => now()])->save();
                $this->audit->record(AuditAction::CompanyCredentialsPrinted, $locked, new: ['version' => $version], actor: $admin);
            }

            return $locked;
        }, self::ATTEMPTS);
    }

    /**
     * Profils recherchés (RG-ENTR-08) : la liste reçue est la sélection complète. Une valeur
     * NOUVELLE doit être active ; une valeur déjà associée peut rester même inactive.
     *
     * @param  list<int>  $filiereIds
     * @param  list<int>  $competenceIds
     */
    public function updateProfiles(Company $company, array $filiereIds, array $competenceIds, User $actor): Company
    {
        return DB::transaction(function () use ($company, $filiereIds, $competenceIds, $actor): Company {
            $locked = $this->lock($company);
            $before = $this->profileLabels($locked);

            $currentFilieres = $locked->filieres()->pluck('filieres.id')->all();
            $currentCompetences = $locked->competences()->pluck('competences.id')->all();

            $this->assertNewActive(Filiere::class, array_diff($filiereIds, $currentFilieres), 'filiere_ids', 'Une filière choisie n’est plus proposée.');
            $this->assertNewActive(Competence::class, array_diff($competenceIds, $currentCompetences), 'competence_ids', 'Une compétence choisie n’est plus proposée.');

            $locked->filieres()->sync($filiereIds);
            $locked->competences()->sync($competenceIds);

            $after = $this->profileLabels($locked);
            if ($before !== $after) {
                $this->audit->record(AuditAction::CompanyProfilesUpdated, $locked, $before, $after, actor: $actor);
            }

            return $locked;
        }, self::ATTEMPTS);
    }

    private function lock(Company $company): Company
    {
        return Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
    }

    private function storeLogo(UploadedFile $logo): string
    {
        $path = Storage::disk(self::LOGO_DISK)->putFile(self::LOGO_DIR, $logo);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Le logo n’a pas pu être enregistré.');
        }

        return $path;
    }

    /** Supprime le fichier après la validation finale de la transaction (englobante comprise). */
    private function discardOnCommit(?string $path): void
    {
        if ($path !== null) {
            DB::afterCommit(fn () => $this->discardLogo($path));
        }
    }

    /**
     * Supprime le fichier si la transaction ENGLOBANTE de l'appelant est annulée. Laravel rattache le
     * rappel à la transaction ouverte au moment de l'appel : il est donc enregistré AVANT d'ouvrir la
     * transaction du service (un rappel enregistré dedans serait perdu à sa validation). Sans
     * transaction englobante, rien n'est enregistré : l'échec interne est géré par le catch.
     *
     * Limite connue (contre-revue Codex T-11, constat 4, passage de relais à T-13) : avec DEUX niveaux
     * englobants, si le niveau intermédiaire est validé puis le niveau supérieur annulé, le rappel est
     * perdu et le nouveau fichier reste orphelin (données et ancien logo restent corrects).
     *
     * @param  callable(): void  $discard
     */
    private function discardIfEnclosingRollsBack(callable $discard): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterRollBack($discard);
        }
    }

    /**
     * Suppression du nouveau fichier exécutée UNE seule fois, partagée entre le catch et le rappel
     * d'annulation (contre-revue Codex T-11, constat 4 : plus de double suppression).
     *
     * @return callable(): void
     */
    private function discardOnce(?string $path): callable
    {
        $done = false;

        return function () use ($path, &$done): void {
            if (! $done) {
                $done = true;
                $this->discardLogo($path);
            }
        };
    }

    private function discardLogo(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::LOGO_DISK)->delete($path);
        }
    }

    /**
     * @param  class-string<ReferentialModel>  $model
     */
    private function assertActive(string $model, mixed $id, string $field, string $message): void
    {
        if ($id === null) {
            return;
        }

        if (! $model::query()->whereKey($id)->where('active', true)->exists()) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    /**
     * @param  class-string<ReferentialModel>  $model
     * @param  array<int, int>  $ids
     */
    private function assertNewActive(string $model, array $ids, string $field, string $message): void
    {
        if ($ids === []) {
            return;
        }

        $active = $model::query()->whereKey(array_values($ids))->where('active', true)->count();
        if ($active !== count(array_unique($ids))) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Company $company): array
    {
        $sector = $company->sector_id !== null ? Sector::query()->find($company->sector_id) : null;

        return [
            'nom' => $company->name,
            'secteur' => $sector?->label,
            'description' => $company->description,
            'site_web' => $company->website,
            'contact' => $company->contact_name,
            'email_contact' => $company->contact_email,
        ];
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function snapshotKeys(array $columns): array
    {
        $map = ['name' => 'nom', 'sector_id' => 'secteur', 'description' => 'description', 'website' => 'site_web', 'contact_name' => 'contact', 'contact_email' => 'email_contact'];

        return array_values(array_intersect_key($map, array_flip($columns)));
    }

    /**
     * @return array{filieres: list<string>, competences: list<string>}
     */
    private function profileLabels(Company $company): array
    {
        return [
            'filieres' => $company->filieres()->orderBy('code')->get()->map(fn (Filiere $f) => $f->displayLabel())->values()->all(),
            'competences' => $company->competences()->orderBy('label')->pluck('label')->values()->all(),
        ];
    }
}
