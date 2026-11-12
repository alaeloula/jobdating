# JobDating — dossier de démarrage

Projet au stade du socle technique, sans fonctionnalités métier. Le socle est initialisé dans `backend/` et `frontend/` : voir leurs README pour le lancement et `docs/PROGRESS.md` pour les validations et limites.

Références dans `docs/reference/` : cahier des charges V2, présentation fonctionnelle, architecture. Ordre des tâches : `docs/BACKLOG.md`.

## Stack spécifiée dans le CDC V2
- Backend : PHP 8.4, Laravel 12, API REST `/api/v1`, Sanctum (cookies/CSRF), Spatie Permission.
- Frontend : React 19, TypeScript, Vite, Tailwind CSS, React Router.
- Base : MySQL 8 strict, migrations Laravel.
- Infrastructure cible : Nginx, stockage privé, jobs, planificateur, Reverb.

Ne pas remplacer ces choix sans décision explicite. L'architecture détaillée est une cible, pas une obligation d'installer chaque composant dès le premier jour.

## Comment démarrer
1. Lire `CLAUDE.md`, `docs/tasks/CURRENT_TASK.md` et `docs/PROGRESS.md`.
2. Consulter les sections pertinentes du CDC, pas nécessairement tout le PDF.
3. Réaliser une tâche verticale à la fois (API + UI + tests).
4. Faire une revue indépendante et tester localement avant commit.

## Attention
Les décisions métier sont dans l'annexe C du CDC ; `docs/DECISIONS.md` liste les décisions techniques et les points encore ouverts.
