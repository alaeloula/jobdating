<?php

namespace App\Enums;

/**
 * Permissions de l'application : une par ligne de la matrice des droits (CDC 3.2).
 *
 * Une permission dit si un rôle peut réaliser l'action. Les droits limités à un
 * périmètre (🔸 dans la matrice, par exemple « ses passages ») sont vérifiés en plus
 * par la policy de la ressource concernée (RG-ACC-02).
 */
enum Permission: string
{
    /** Gérer les comptes et les rôles. */
    case UsersManage = 'users.manage';

    /** Modifier un apprenant, réinitialiser son mot de passe. */
    case LearnersUpdate = 'learners.update';

    /** Consulter la CNIE d'un apprenant. */
    case LearnersViewCnie = 'learners.view_cnie';

    /** Gérer les référentiels. */
    case ReferentialsManage = 'referentials.manage';

    /** Créer / modifier un événement. */
    case EventsManage = 'events.manage';

    /** Gérer le programme de l'événement. */
    case ProgramManage = 'program.manage';

    /** Ajouter ou retirer des participants. */
    case ParticipantsManage = 'participants.manage';

    /** Saisir l'emplacement des entreprises. */
    case LocationsManage = 'locations.manage';

    /** Consulter le programme. */
    case ProgramView = 'program.view';

    /** Consulter la liste des apprenants. */
    case LearnersView = 'learners.view';

    /** Consulter et télécharger un CV validé. */
    case CvView = 'cv.view';

    /** Consulter les entreprises. */
    case CompaniesView = 'companies.view';

    /** Enregistrer un passage. */
    case PassagesCreate = 'passages.create';

    /** Saisir / modifier une évaluation. */
    case EvaluationsUpdate = 'evaluations.update';

    /** Annuler un passage. */
    case PassagesCancel = 'passages.cancel';

    /** Changer l'apprenant d'un passage. */
    case PassagesChangeLearner = 'passages.change_learner';

    /** Consulter les évaluations d'un apprenant. */
    case EvaluationsView = 'evaluations.view';

    /** Tableau de bord global et alertes. */
    case DashboardGlobal = 'dashboard.global';

    /** Statistiques par entreprise. */
    case StatsCompanies = 'stats.companies';

    /** Statistiques par apprenant. */
    case StatsLearners = 'stats.learners';

    /** Exporter. */
    case ExportsCreate = 'exports.create';

    /** Envoyer une notification. */
    case NotificationsSend = 'notifications.send';

    /** Téléverser ou importer le CV d'un apprenant. */
    case CvUpload = 'cv.upload';

    /** Consulter l'historique des ajouts de CV. */
    case CvHistory = 'cv.history';

    /** Consulter le journal d'audit. */
    case AuditView = 'audit.view';
}
