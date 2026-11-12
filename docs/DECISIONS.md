# Décisions et points ouverts

## Décisions métier

Les décisions métier validées sont dans l'**annexe C du cahier des charges** (`reference/CAHIER_DES_CHARGES_V2.md`). Elles sont déjà intégrées dans le corps du document. Ne pas les recopier ici : l'annexe C fait foi.

## Décisions techniques

| Date | Décision | Source |
|---|---|---|
| 12/11/2026 | Organisation du dépôt : `backend/` (Laravel) et `frontend/` (React), documentation dans `docs/` | Initialisation, ARCHITECTURE 3 |
| 12/11/2026 | Fuseau horaire `Africa/Casablanca` : la fin du jour de l'événement est minuit, heure du Maroc | RG-PAS-10 |
| 12/11/2026 | Sessions en base de données, durée 720 minutes (12 h). Le pilote `database` permet de fermer toutes les sessions d'un utilisateur | RG-AUTH-02, RG-AUTH-12, RG-ACC-05 |
| 12/11/2026 | File d'attente et cache en base de données (pas de Redis à exploiter) | ARCHITECTURE 1.2 |
| 12/11/2026 | Tests backend sur MySQL (`jobdating_test`), pas sur SQLite, pour respecter le mode strict et les calculs des statistiques | CDC 9.4 |
| 12/11/2026 | Locale `fr` ; traductions françaises des messages de validation à ajouter | CDC 6.3 |
| 12/11/2026 | TypeScript en mode strict | CDC 9.2 |
| 12/11/2026 | En développement, Vite redirige `/api`, `/sanctum` et `/up` vers Laravel (même origine, condition des cookies Sanctum) | CDC 9.5, PC-36 |
| 12/11/2026 | Lint frontend avec oxlint (installé à l'initialisation) au lieu d'ESLint + Prettier proposés dans ARCHITECTURE 1.2 | Initialisation |

## Points ouverts

| ID | Point | Bloque | À qui |
|---|---|---|---|
| PC-26 | API de la plateforme CME : documentation, environnement de test, identifiant commun, authentification | CV-02 uniquement | CME, TECH |
| PC-43 | Modèle de l'export Excel de l'équipe CME | Mise en forme de l'export uniquement | PO, CME |
| ARCH-01 | Hébergement de production et nom de domaine | Mise en ligne de la semaine 1 | TECH |
| ARCH-02 | Validation des choix « proposés » de ARCHITECTURE 1.2, dont PHPUnit (installé) ou Pest (proposé) | Tâches de développement | TECH |
| REP-01 | Rapprochement des apprenants de l'ancienne application, qui n'a pas de CNIE (proposition : par e-mail professionnel) | Reprise des passages | PO, TECH |
| REP-02 | Anciennes rencontres `scheduled`, `in_progress`, `cancelled` : reprises ou ignorées | Reprise | PO |
| REP-03 | Champ de notes historiques sur 5 à ajouter au modèle `evaluations` (CDC 8.8) | Modèle de données | TECH |
| REP-04 | Calendrier : entreprises reprises avant l'événement, passages historiques après | Planning | PO, TECH |
| ENV-01 | PHP 8.4 exact et MySQL 8 à valider en local (PHP 8.5 utilisé à l'initialisation) | Tests | TECH |
