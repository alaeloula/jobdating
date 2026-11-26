<?php

namespace App\Enums;

/**
 * Actions journalisées (TRA-01). Les actions des tâches suivantes (passages, évaluations,
 * CV, imports, exports) s'ajouteront ici, avec leur catégorie et leur libellé.
 */
enum AuditAction: string
{
    case LoginSucceeded = 'auth.login_succeeded';
    case LoginFailed = 'auth.login_failed';
    case LoginThrottled = 'auth.login_throttled';
    case Logout = 'auth.logout';

    case AccountCreated = 'account.created';
    case AccountUpdated = 'account.updated';
    case AccountDeactivated = 'account.deactivated';
    case AccountReactivated = 'account.reactivated';
    case AccountDeleted = 'account.deleted';
    case AccountRestored = 'account.restored';
    case RoleChanged = 'account.role_changed';
    case ActivationLinkSent = 'account.activation_link_sent';
    case PasswordResetLinkSent = 'account.password_reset_link_sent';
    case LinkEmailFailed = 'account.link_email_failed';
    case AccountActivated = 'account.activated';
    case PasswordReset = 'account.password_reset';
    case PasswordChanged = 'account.password_changed';
    case CompanyCreated = 'company.created';
    case CompanyUpdated = 'company.updated';
    case CompanyProfilesUpdated = 'company.profiles_updated';
    case CompanyCredentialsPrinted = 'company.credentials_printed';

    case LearnerCreated = 'learner.created';
    case LearnerUpdated = 'learner.updated';
    case LearnerCompetencesUpdated = 'learner.competences_updated';
    case LearnerBulkDeactivated = 'learner.bulk_deactivated';
    case LearnerBulkCompetenceAdded = 'learner.bulk_competence_added';
    // Valeur HISTORIQUE, plus jamais écrite (décision du 19/11 : plus de journalisation de chaque
    // consultation de la CNIE). Conservée pour lire les anciennes lignes du journal (contre-revue T-12, constat 7).
    case LearnerCnieViewed = 'learner.cnie_viewed';

    case LearnerImportConfirmed = 'learner_import.confirmed';
    case LearnerImportCompleted = 'learner_import.completed';
    case LearnerImportFailed = 'learner_import.failed';
    case LearnerImportPasswordsDownloaded = 'learner_import.passwords_downloaded';
    case LearnerImportPasswordsDeleted = 'learner_import.passwords_deleted';

    case CompanyImportConfirmed = 'company_import.confirmed';
    case CompanyImportCompleted = 'company_import.completed';
    case CompanyImportFailed = 'company_import.failed';
    case CompanyImportCredentialsViewed = 'company_import.credentials_viewed';
    case CompanyImportCredentialsPrinted = 'company_import.credentials_printed';
    case CompanyImportCredentialsDeleted = 'company_import.credentials_deleted';

    case EventCreated = 'event.created';
    case EventUpdated = 'event.updated';
    case EventCurrentSet = 'event.current_set';
    case EventCurrentUnset = 'event.current_unset';
    case EventArchived = 'event.archived';
    case EventUnarchived = 'event.unarchived';
    case EventDeleted = 'event.deleted';
    case EventCompanyAdded = 'event_company.added';
    case EventCompanyRemoved = 'event_company.removed';
    case EventCompanyLocationUpdated = 'event_company.location_updated';
    case EventLearnerRemoved = 'event_learner.removed';
    case EventLearnerReinstated = 'event_learner.reinstated';

    case ReferentialCreated = 'referential.created';
    case ReferentialUpdated = 'referential.updated';
    case ReferentialDeactivated = 'referential.deactivated';
    case ReferentialReactivated = 'referential.reactivated';
    case ReferentialReordered = 'referential.reordered';

    public function category(): AuditCategory
    {
        return match ($this) {
            self::LoginSucceeded, self::LoginFailed, self::LoginThrottled, self::Logout => AuditCategory::Connexions,
            self::AccountCreated, self::AccountUpdated, self::AccountDeactivated, self::AccountReactivated,
            self::AccountDeleted, self::AccountRestored, self::RoleChanged, self::ActivationLinkSent,
            self::PasswordResetLinkSent, self::LinkEmailFailed, self::AccountActivated, self::PasswordReset,
            self::PasswordChanged => AuditCategory::Comptes,
            self::CompanyCreated, self::CompanyUpdated, self::CompanyProfilesUpdated,
            self::CompanyCredentialsPrinted => AuditCategory::Entreprises,
            self::LearnerCreated, self::LearnerUpdated, self::LearnerCompetencesUpdated,
            self::LearnerBulkDeactivated, self::LearnerBulkCompetenceAdded => AuditCategory::Apprenants,
            self::LearnerCnieViewed => AuditCategory::Cnie,
            self::LearnerImportConfirmed, self::LearnerImportCompleted, self::LearnerImportFailed,
            self::LearnerImportPasswordsDownloaded, self::LearnerImportPasswordsDeleted,
            self::CompanyImportConfirmed, self::CompanyImportCompleted, self::CompanyImportFailed,
            self::CompanyImportCredentialsViewed, self::CompanyImportCredentialsPrinted,
            self::CompanyImportCredentialsDeleted => AuditCategory::ImportsExports,
            self::EventCreated, self::EventUpdated, self::EventCurrentSet, self::EventCurrentUnset,
            self::EventArchived, self::EventUnarchived, self::EventDeleted, self::EventCompanyAdded,
            self::EventCompanyRemoved, self::EventCompanyLocationUpdated, self::EventLearnerRemoved,
            self::EventLearnerReinstated => AuditCategory::Evenements,
            self::ReferentialCreated, self::ReferentialUpdated, self::ReferentialDeactivated,
            self::ReferentialReactivated, self::ReferentialReordered => AuditCategory::Referentiels,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::LoginSucceeded => 'Connexion réussie',
            self::LoginFailed => 'Connexion refusée',
            self::LoginThrottled => 'Connexion bloquée (trop de tentatives)',
            self::Logout => 'Déconnexion',
            self::AccountCreated => 'Compte créé',
            self::AccountUpdated => 'Compte modifié',
            self::AccountDeactivated => 'Compte désactivé',
            self::AccountReactivated => 'Compte réactivé',
            self::AccountDeleted => 'Compte supprimé',
            self::AccountRestored => 'Compte restauré',
            self::RoleChanged => 'Rôle modifié',
            self::ActivationLinkSent => 'Lien d’activation remis à la messagerie',
            self::PasswordResetLinkSent => 'Lien de réinitialisation remis à la messagerie',
            self::LinkEmailFailed => 'Échec de remise d’un e-mail de lien',
            self::AccountActivated => 'Compte activé',
            self::PasswordReset => 'Mot de passe réinitialisé',
            self::PasswordChanged => 'Mot de passe changé',
            self::CompanyCreated => 'Entreprise créée',
            self::CompanyUpdated => 'Fiche entreprise modifiée',
            self::CompanyProfilesUpdated => 'Profils recherchés modifiés',
            self::CompanyCredentialsPrinted => 'Fiche de connexion imprimée',
            self::LearnerCreated => 'Apprenant créé',
            self::LearnerUpdated => 'Fiche apprenant modifiée',
            self::LearnerCompetencesUpdated => 'Compétences de l’apprenant modifiées',
            self::LearnerBulkDeactivated => 'Apprenants désactivés en masse',
            self::LearnerBulkCompetenceAdded => 'Compétence ajoutée à une sélection d’apprenants',
            self::LearnerCnieViewed => 'CNIE consultée (ancienne version)',
            self::LearnerImportConfirmed => 'Import d’apprenants confirmé',
            self::LearnerImportCompleted => 'Import d’apprenants terminé',
            self::LearnerImportFailed => 'Import d’apprenants en échec',
            self::LearnerImportPasswordsDownloaded => 'Fichier des mots de passe téléchargé',
            self::LearnerImportPasswordsDeleted => 'Fichier des mots de passe supprimé',
            self::CompanyImportConfirmed => 'Import d’entreprises confirmé',
            self::CompanyImportCompleted => 'Import d’entreprises terminé',
            self::CompanyImportFailed => 'Import d’entreprises en échec',
            self::CompanyImportCredentialsViewed => 'Fiches de connexion de l’import affichées',
            self::CompanyImportCredentialsPrinted => 'Fiches de connexion de l’import imprimées',
            self::CompanyImportCredentialsDeleted => 'Mots de passe de l’import supprimés',
            self::EventCreated => 'Événement créé',
            self::EventUpdated => 'Événement modifié',
            self::EventCurrentSet => 'Événement désigné comme courant',
            self::EventCurrentUnset => 'Statut courant retiré',
            self::EventArchived => 'Événement archivé',
            self::EventUnarchived => 'Événement désarchivé',
            self::EventDeleted => 'Événement supprimé',
            self::EventCompanyAdded => 'Entreprise ajoutée à un événement',
            self::EventCompanyRemoved => 'Entreprise retirée d’un événement',
            self::EventCompanyLocationUpdated => 'Emplacement d’une entreprise modifié',
            self::EventLearnerRemoved => 'Apprenant retiré d’un événement',
            self::EventLearnerReinstated => 'Apprenant réinscrit à un événement',
            self::ReferentialCreated => 'Référentiel : valeur ajoutée',
            self::ReferentialUpdated => 'Référentiel : valeur modifiée',
            self::ReferentialDeactivated => 'Référentiel : valeur désactivée',
            self::ReferentialReactivated => 'Référentiel : valeur réactivée',
            self::ReferentialReordered => 'Référentiel : ordre modifié',
        };
    }
}
