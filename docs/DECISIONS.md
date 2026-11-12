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
| 12/11/2026 | Un seul dépôt GitHub (`alaeloula/jobdating`) avec `backend/` et `frontend/` ; workflow `main` (production) / `develop` (intégration, branche par défaut) / `feature/T-xx-nom` ; `main` et `develop` protégées (pull request obligatoire, pas de push direct ni de force push) | Choix du responsable technique |
| 12/11/2026 | Développement local : Herd pour PHP 8.4 ; Docker (`docker-compose.yml`) pour MySQL 8 (port 3307) et Mailpit | Choix du responsable technique |
| 12/11/2026 | Production : conteneurs Docker sur le serveur YouCode / 1337 qui héberge l'intranet, pour isoler JobDating des autres applications ; Dockerfiles à produire avec T-01 | Choix du responsable technique, détails en ARCH-01 |
| 12/11/2026 | Lint frontend avec oxlint (installé à l'initialisation) au lieu d'ESLint + Prettier proposés dans ARCHITECTURE 1.2 | Initialisation |

## Points ouverts

| ID | Point | Bloque | À qui |
|---|---|---|---|
| PC-26 | API de la plateforme CME : documentation, environnement de test, identifiant commun, authentification | CV-02 uniquement | CME, TECH |
| PC-43 | Modèle de l'export Excel de l'équipe CME | Mise en forme de l'export uniquement | PO, CME |
| ARCH-01 | Serveur YouCode / 1337 (celui de l'intranet) : (1) Docker et Compose disponibles ? (2) adresse de l'application, par ex. `jobdating.youcode.ma` (même domaine parent requis par Sanctum) ; (3) proxy et HTTPS devant les applications, WebSockets pour Reverb ; (4) MySQL existant (version ?) ou conteneur dédié ; (5) sauvegardes et stockage privé des CV ; (6) mode de déploiement (SSH, GitHub, administrateur) | Mise en ligne T-01 | TECH, administrateurs du serveur |
| ARCH-02 | Validation des choix « proposés » de ARCHITECTURE 1.2, dont PHPUnit (installé) ou Pest (proposé) | Tâches de développement | TECH |
| REP-01 | Rapprochement des apprenants de l'ancienne application, qui n'a pas de CNIE (proposition : par e-mail professionnel) | Reprise des passages | PO, TECH |
| REP-02 | Anciennes rencontres `scheduled`, `in_progress`, `cancelled` : reprises ou ignorées | Reprise | PO |
| REP-03 | Champ de notes historiques sur 5 à ajouter au modèle `evaluations` (CDC 8.8) | Modèle de données | TECH |
| REP-04 | Calendrier : entreprises reprises avant l'événement, passages historiques après | Planning | PO, TECH |
| ENV-01 | PHP 8.4 exact et MySQL 8 à valider en local (PHP 8.5 utilisé à l'initialisation) | Tests | TECH |
