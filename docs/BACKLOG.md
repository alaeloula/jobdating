# Backlog – JobDating

Suivi dans Jira : https://alaaeloula.atlassian.net/jira/software/projects/JD/boards/35 (espace `JD`, un sprint par semaine, un epic par bloc). Le numéro Jira est indiqué entre parenthèses ; le citer dans le nom de branche et les commits, par ex. `feature/T-04-connexion` et « JD-11 T-04 : … ».

Une tâche à la fois, dans l'ordre. Chaque tâche est livrée complète : API + interface + tests (voir « Définition de terminé », ARCHITECTURE 11).

Références : user stories US-01 à US-12 (présentation fonctionnelle, partie 9) ; fonctionnalités et règles (CDC V2, sections 4 et 5).

Priorité : **J** = nécessaire le jour de l'événement ; **A** = utilisée après la journée (peut être livrée juste après l'événement si le planning glisse).

## Semaine 1 – Socle

Ordre de réalisation revu le 12/11/2026 : les rôles, la connexion et l'audit précèdent les référentiels, qui en ont besoin.

| # | Tâche | Références | Prio |
|---|---|---|---|
| T-00 (JD-8) | Installer les outils et paquets du socle (voir `tasks/CURRENT_TASK.md`) | ARCHITECTURE 1, 10 | J |
| T-03 (JD-10) | Rôles et permissions (seeder versionné) + test de chaque cellule de la matrice | CDC 3.2, 3.3 | J |
| T-04 (JD-11) | Connexion, déconnexion, accueil par rôle, limitation des tentatives, session 12 h | AUTH-01, RG-AUTH-01 à 05 | J |
| T-06 (JD-13) | Journal d'audit (service + table en ajout seul) | TRA-01 | J |
| T-02 (JD-9) | Référentiels : campus, filières, promotions, classes, secteurs, opportunités, étiquettes | ADM-09 | J |
| T-05 (JD-12) | Activation, oubli et changement du mot de passe | AUTH-02, AUTH-03 | J |

## Semaine 2 – Préparer l'événement (US-01)

| # | Tâche | Références | Prio |
|---|---|---|---|
| T-10 (JD-15) | Gestion des utilisateurs (CME, Direction, administrateurs) | ADM-01 | J |
| T-11 (JD-16) | Entreprises : création, nom d'utilisateur et mot de passe générés, fiche imprimable | ADM-02 | J |
| T-12 (JD-17) | Apprenants : fiche, modification, réinitialisation, désactivation en masse | ADM-03 | J |
| T-13 (JD-18) | Import du fichier YouCode School Admin (aperçu, contrôles, rapport, fichier des mots de passe) | ADM-04, RG-IMP-01 à 07 | J |
| T-14 (JD-19) | Événement courant, inscription automatique des participants, emplacements | ADM-05, ADM-07 | J |
| T-15 (JD-20) | Programme de l'événement | ADM-08 | J |
| T-16 (JD-21) | Reprise des **entreprises** de l'ancienne application | CDC 8.8, REP-04 | J |

## Semaine 3 – CV et profils (US-02, US-03, US-04)

| # | Tâche | Références | Prio |
|---|---|---|---|
| T-20 (JD-22) | Téléversement et remplacement du CV (PDF, chiffré, historique) | CV-01, CV-04 | J |
| T-21 (JD-23) | Consultation du CV par lien temporaire, journalisée | CV-03, RG-CV-01 à 03 | J |
| T-22 (JD-24) | Import du CV depuis la plateforme CME (client factice tant que PC-26 est ouvert) | CV-02 | A |
| T-23 (JD-25) | Liste des apprenants côté entreprise (recherche, badge « Rencontré × n ») | ENT-02, RG-ENT-01 | J |
| T-24 (JD-26) | Fiche apprenant côté entreprise | ENT-03 | J |

## Semaine 4 – Rencontre et évaluation (US-05, US-06) : cœur du jour J

| # | Tâche | Références | Prio |
|---|---|---|---|
| T-30 (JD-27) | Démarrer et terminer un entretien (passage, anti double soumission) | ENT-04, RG-PAS-01 à 12 | J |
| T-31 (JD-28) | Grille d'évaluation avec sauvegarde automatique | ENT-05, RG-PAS-11, RG-EVA | J |
| T-32 (JD-29) | Compléter, modifier, annuler (règle du jour de l'événement) | ENT-06 | J |
| T-33 (JD-30) | Tableau de bord entreprise et « Mes passages » | ENT-01, ENT-07 | J |
| T-34 (JD-31) | Test de bout en bout du parcours entreprise sur mobile (375 px) | CDC 11.1 | J |

## Semaine 5 – Suivi de la journée (US-07, US-11, US-12) et mise en ligne

| # | Tâche | Références | Prio |
|---|---|---|---|
| T-40 (JD-32) | Espace apprenant : « Ma journée », programme, passages, état du CV | APP-00 à APP-03 | J |
| T-41 (JD-33) | Notifications internes et temps réel (Reverb) | NOTIF-01 | J |
| T-42 (JD-34) | Alertes automatiques des apprenants sans rencontre | NOTIF-02, STAT-06 | J |
| T-43 (JD-35) | Régularisation d'un passage par l'administrateur | ADM-10 | J |
| T-01 (JD-14) | Mise en ligne sur le VPS YouCode / 1337 : Docker, HTTPS, déploiement automatique depuis `main`, sauvegardes hors VPS. Peut être avancée ; au plus tard avant la recette | ARCHITECTURE 9 | J |

## Semaine 6 – Recette et répétition

| # | Tâche | Références | Prio |
|---|---|---|---|
| T-50 (JD-36) | Recette par l'équipe CME en préproduction | CDC 11.8, EXI-ERG-02 | J |
| T-51 (JD-37) | Test de charge (224 apprenants, 7 CME, entreprises) | EXI-PERF-05 | J |
| T-52 (JD-38) | Sauvegarde et restauration testées | EXI-FIA-02 | J |
| T-53 (JD-39) | Répétition générale | — | J |

## Après la journée si nécessaire (US-08, US-09, US-10)

| # | Tâche | Références | Prio |
|---|---|---|---|
| T-60 (JD-40) | Service de statistiques unique et filtres | STAT-01 à STAT-04, RG-STAT, RG-FIL | A |
| T-61 (JD-41) | Exports CSV et Excel en flux (modèle PC-43) + journal des exports | STAT-05, ENT-09, TRA-02 | A |
| T-62 (JD-42) | Espace Direction en lecture seule | CDC 3.1 | A |
| T-63 (JD-43) | Reprise des **passages historiques** | CDC 8.8, REP-01 à 03 | A |
