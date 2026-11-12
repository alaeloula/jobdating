<?php

namespace App\Enums;

/**
 * Rôles de l'application (CDC 3.1). Un compte a un seul rôle (RG-ACC-06).
 */
enum Role: string
{
    case Administrateur = 'administrateur';
    case Cme = 'cme';
    case Direction = 'direction';
    case Entreprise = 'entreprise';
    case Apprenant = 'apprenant';

    public function label(): string
    {
        return match ($this) {
            self::Administrateur => 'Administrateur',
            self::Cme => 'CME – Chargé Média Emploi',
            self::Direction => 'Direction',
            self::Entreprise => 'Entreprise',
            self::Apprenant => 'Apprenant',
        };
    }

    /**
     * Permissions du rôle, d'après la matrice des droits (CDC 3.2).
     *
     * L'administrateur reçoit chaque permission explicitement, sans règle « peut tout » :
     * ses propres restrictions (CDC 3.1) restent ainsi vérifiables.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Administrateur => Permission::cases(),
            self::Cme => [
                Permission::ReferentialsManage,
                Permission::ProgramManage,
                Permission::LocationsManage,
                Permission::ProgramView,
                Permission::LearnersView,
                Permission::CvView,
                Permission::CompaniesView,
                Permission::EvaluationsView,
                Permission::DashboardGlobal,
                Permission::StatsCompanies,
                Permission::StatsLearners,
                Permission::ExportsCreate,
                Permission::NotificationsSend,
                Permission::CvUpload,
                Permission::CvHistory,
            ],
            self::Direction => [
                Permission::ProgramView,
                Permission::LearnersView,
                Permission::CvView,
                Permission::CompaniesView,
                Permission::EvaluationsView,
                Permission::DashboardGlobal,
                Permission::StatsCompanies,
                Permission::StatsLearners,
                Permission::ExportsCreate,
            ],
            // Périmètre limité (🔸) : ses événements, ses passages, ses données.
            self::Entreprise => [
                Permission::ProgramView,
                Permission::LearnersView,
                Permission::CvView,
                Permission::CompaniesView,
                Permission::PassagesCreate,
                Permission::EvaluationsUpdate,
                Permission::PassagesCancel,
                Permission::EvaluationsView,
                Permission::StatsCompanies,
                Permission::ExportsCreate,
                Permission::NotificationsSend,
            ],
            // Périmètre limité (🔸) : ses événements, son CV. Ses propres passages
            // (APP-02, sans évaluation ni commentaire) relèvent d'une policy, pas d'une permission.
            self::Apprenant => [
                Permission::ProgramView,
                Permission::CvView,
                Permission::CompaniesView,
            ],
        };
    }
}
