<?php

namespace App\Services\Imports;

use App\Enums\AuditAction;
use App\Models\Company;
use App\Models\CompanyImport;
use App\Models\Event;
use App\Models\EventCompany;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Fiches de connexion d'un import d'entreprises (plan T-64 v1.3, § 7 ; décisions 6, 9 et 10 du 25/11).
 *
 * Une fiche est VALIDE si le compte n'est pas désactivé, si la version des identifiants est celle de
 * l'import ET si le hachage actuel du mot de passe est identique au hachage d'origine (simple
 * comparaison de chaînes : tout changement ou réinitialisation la rend caduque). Le hachage n'est
 * jamais envoyé au navigateur.
 */
class CompanyImportCredentials
{
    public const EXCLUDED_DEACTIVATED = 'Compte désactivé';

    public const EXCLUDED_CHANGED = 'Mot de passe changé ou réinitialisé depuis l’import';

    public const EXCLUDED_MISSING = 'Entreprise introuvable';

    public const NOTICE_EVENT_DELETED = 'Événement supprimé';

    public const NOTICE_REMOVED = 'Ne participe plus à l’événement';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{sheets: list<array<string, mixed>>, excluded: list<array{name: string, reason: string}>}
     */
    public function sheets(CompanyImport $import, User $admin): array
    {
        $credentials = $import->credentials ?? [];
        $companies = Company::query()->with('user')->whereIn('id', array_column($credentials, 'company_id'))->get()->keyBy('id');
        $event = $import->event_id === null ? null : Event::query()->find($import->event_id);
        $placements = $event === null ? collect() : EventCompany::query()->where('event_id', $event->id)
            ->whereIn('company_id', array_column($credentials, 'company_id'))->get()->keyBy('company_id');

        $sheets = [];
        $excluded = [];
        foreach ($credentials as $credential) {
            $company = $companies->get($credential['company_id']);
            $reason = $this->invalidity($company, $credential);
            if ($reason !== null) {
                $excluded[] = ['name' => $credential['name'], 'reason' => $reason];

                continue;
            }
            /** @var Company $company */
            $placement = $placements->get($company->id);
            $sheets[] = [
                'company_id' => $company->id,
                'name' => $company->name,
                'username' => $credential['username'],
                'password' => $credential['password'],
                'version' => $credential['version'],
                'url' => config('app.frontend_url'),
                'event' => $event === null ? null : [
                    'id' => $event->id,
                    'name' => $event->fullName(),
                    'date' => $event->date->toDateString(),
                    'location' => $event->location,
                    'floor' => $placement?->floor,
                    'table_label' => $placement?->table_label,
                ],
                'notice' => match (true) {
                    $event === null => self::NOTICE_EVENT_DELETED,
                    $placement === null => self::NOTICE_REMOVED,
                    default => null,
                },
            ];
        }

        $this->audit->record(AuditAction::CompanyImportCredentialsViewed, $import, new: ['fiches' => count($sheets), 'exclues' => count($excluded)], actor: $admin);

        return ['sheets' => $sheets, 'excluded' => $excluded];
    }

    /**
     * « J'ai imprimé les fiches » : pour chaque entreprise, dans l'ordre des identifiants, verrous
     * entreprise → compte (ordre existant), re-contrôle de validité, puis marquage. Idempotent ; une
     * fiche devenue caduque entre-temps n'est pas marquée.
     *
     * @return array{marked: int, skipped: int}
     */
    public function markPrinted(int $importId, User $admin): array
    {
        return DB::transaction(function () use ($importId, $admin): array {
            $import = CompanyImport::query()->lockForUpdate()->findOrFail($importId);
            abort_unless($import->hasCredentials(), 404);

            $credentials = $import->credentials ?? [];
            usort($credentials, fn (array $a, array $b) => $a['company_id'] <=> $b['company_id']);
            $marked = 0;
            $skipped = 0;
            foreach ($credentials as $credential) {
                $company = Company::query()->whereKey($credential['company_id'])->lockForUpdate()->first();
                if ($company !== null) {
                    $company->setRelation('user', User::withTrashed()->whereKey($company->user_id)->lockForUpdate()->first());
                }
                if ($this->invalidity($company, $credential) !== null) {
                    $skipped++;

                    continue;
                }
                /** @var Company $company */
                if ($company->credentials_printed_at === null) {
                    $company->forceFill(['credentials_printed_at' => now()])->save();
                    $marked++;
                }
            }

            $this->audit->record(AuditAction::CompanyImportCredentialsPrinted, $import, new: ['marquees' => $marked, 'ignorees' => $skipped], actor: $admin);

            return ['marked' => $marked, 'skipped' => $skipped];
        }, 3);
    }

    public function delete(int $importId, User $admin): void
    {
        DB::transaction(function () use ($importId, $admin): void {
            $import = CompanyImport::query()->lockForUpdate()->findOrFail($importId);
            abort_unless($import->hasCredentials(), 404);
            $import->forceFill(['credentials' => null, 'credentials_deleted_at' => now()])->save();
            $this->audit->record(AuditAction::CompanyImportCredentialsDeleted, $import, actor: $admin);
        });
    }

    /**
     * @param  array{company_id: int, user_id: int, name: string, username: string, password: string, version: int, hash: string}  $credential
     */
    private function invalidity(?Company $company, array $credential): ?string
    {
        $user = $company?->user;
        if ($company === null || $user === null || $user->trashed()) {
            return self::EXCLUDED_MISSING;
        }
        if ($user->isDeactivated()) {
            return self::EXCLUDED_DEACTIVATED;
        }
        if ($company->credentials_version !== $credential['version'] || ! hash_equals((string) $user->getAuthPassword(), $credential['hash'])) {
            return self::EXCLUDED_CHANGED;
        }

        return null;
    }
}
