# Backlog – JobDating

Une tâche à la fois, dans l'ordre. Chaque tâche est livrée complète : API + interface + tests (voir « Définition de terminé », ARCHITECTURE 11).

Références : user stories US-01 à US-12 (présentation fonctionnelle, partie 9) ; fonctionnalités et règles (CDC V2, sections 4 et 5).

Priorité : **J** = nécessaire le jour de l'événement ; **A** = utilisée après la journée (peut être livrée juste après l'événement si le planning glisse).

## Semaine 1 – Socle

| # | Tâche | Références | Prio |
|---|---|---|---|
| T-00 | Installer les outils et paquets du socle (voir `tasks/CURRENT_TASK.md`) | ARCHITECTURE 1, 10 | J |
| T-01 | Mise en ligne minimale sur l'hébergement final (HTTPS, domaine, sessions, `/up`) | ARCHITECTURE 9 | J |
| T-02 | Référentiels : campus, filières, promotions, classes, secteurs, opportunités, étiquettes | ADM-09 | J |
| T-03 | Rôles et permissions (seeder versionné) + test de chaque cellule de la matrice | CDC 3.2, 3.3 | J |
| T-04 | Connexion, déconnexion, accueil par rôle, limitation des tentatives, session 12 h | AUTH-01, RG-AUTH-01 à 05 | J |
| T-05 | Activation, oubli et changement du mot de passe | AUTH-02, AUTH-03 | J |
| T-06 | Journal d'audit (service + table en ajout seul) | TRA-01 | J |

## Semaine 2 – Préparer l'événement (US-01)

| # | Tâche | Références | Prio |
|---|---|---|---|
| T-10 | Gestion des utilisateurs (CME, Direction, administrateurs) | ADM-01 | J |
| T-11 | Entreprises : création, nom d'utilisateur et mot de passe générés, fiche imprimable | ADM-02 | J |
| T-12 | Apprenants : fiche, modification, réinitialisation, désactivation en masse | ADM-03 | J |
| T-13 | Import du fichier YouCode School Admin (aperçu, contrôles, rapport, fichier des mots de passe) | ADM-04, RG-IMP-01 à 07 | J |
| T-14 | Événement courant, inscription automatique des participants, emplacements | ADM-05, ADM-07 | J |
| T-15 | Programme de l'événement | ADM-08 | J |
| T-16 | Reprise des **entreprises** de l'ancienne application | CDC 8.8, REP-04 | J |

## Semaine 3 – CV et profils (US-02, US-03, US-04)

| # | Tâche | Références | Prio |
|---|---|---|---|
| T-20 | Téléversement et remplacement du CV (PDF, chiffré, historique) | CV-01, CV-04 | J |
| T-21 | Consultation du CV par lien temporaire, journalisée | CV-03, RG-CV-01 à 03 | J |
| T-22 | Import du CV depuis la plateforme CME (client factice tant que PC-26 est ouvert) | CV-02 | A |
| T-23 | Liste des apprenants côté entreprise (recherche, badge « Rencontré × n ») | ENT-02, RG-ENT-01 | J |
| T-24 | Fiche apprenant côté entreprise | ENT-03 | J |

## Semaine 4 – Rencontre et évaluation (US-05, US-06) : cœur du jour J

| # | Tâche | Références | Prio |
|---|---|---|---|
| T-30 | Démarrer et terminer un entretien (passage, anti double soumission) | ENT-04, RG-PAS-01 à 12 | J |
| T-31 | Grille d'évaluation avec sauvegarde automatique | ENT-05, RG-PAS-11, RG-EVA | J |
| T-32 | Compléter, modifier, annuler (règle du jour de l'événement) | ENT-06 | J |
| T-33 | Tableau de bord entreprise et « Mes passages » | ENT-01, ENT-07 | J |
| T-34 | Test de bout en bout du parcours entreprise sur mobile (375 px) | CDC 11.1 | J |

## Semaine 5 – Suivi de la journée (US-07, US-11, US-12)

| # | Tâche | Références | Prio |
|---|---|---|---|
| T-40 | Espace apprenant : « Ma journée », programme, passages, état du CV | APP-00 à APP-03 | J |
| T-41 | Notifications internes et temps réel (Reverb) | NOTIF-01 | J |
| T-42 | Alertes automatiques des apprenants sans rencontre | NOTIF-02, STAT-06 | J |
| T-43 | Régularisation d'un passage par l'administrateur | ADM-10 | J |

## Semaine 6 – Recette et répétition

| # | Tâche | Références | Prio |
|---|---|---|---|
| T-50 | Recette par l'équipe CME en préproduction | CDC 11.8, EXI-ERG-02 | J |
| T-51 | Test de charge (224 apprenants, 7 CME, entreprises) | EXI-PERF-05 | J |
| T-52 | Sauvegarde et restauration testées | EXI-FIA-02 | J |
| T-53 | Répétition générale | — | J |

## Après la journée si nécessaire (US-08, US-09, US-10)

| # | Tâche | Références | Prio |
|---|---|---|---|
| T-60 | Service de statistiques unique et filtres | STAT-01 à STAT-04, RG-STAT, RG-FIL | A |
| T-61 | Exports CSV et Excel en flux (modèle PC-43) + journal des exports | STAT-05, ENT-09, TRA-02 | A |
| T-62 | Espace Direction en lecture seule | CDC 3.1 | A |
| T-63 | Reprise des **passages historiques** | CDC 8.8, REP-01 à 03 | A |
