# Avancement

## 2026-11-12 — Initialisation technique

- Markdown initiaux analysés : README.md, CLAUDE.md et AGENTS.md.
- Absents au départ : docs/, CDC V2, présentation, tâche courante, décisions et suivi.
- Structure documentaire créée, sans inventer les références métier.
- Squelettes backend/ (Laravel 12) et frontend/ (React 19, TypeScript, Vite, Tailwind, React Router).
- Résolution Composer ciblée PHP 8.4 ; runtime local disponible : PHP 8.5.12.
- MySQL configuré, mode strict Laravel conservé ; aucune migration exécutée.
- Session/cache fichiers et queue synchrone pour démarrer sans base disponible.
- Préfixe API /api/v1 préparé, sans endpoint métier.
- Aucune authentification ni règle métier implémentée. Sanctum, Spatie et Reverb différés.

## Validation

Contrôles exécutés localement le 2026-11-12 :

- `composer validate --strict` : réussi.
- `php artisan test --no-ansi` : 2 tests du squelette réussis, 2 assertions. Ces tests ne couvrent aucune règle métier.
- `php vendor/bin/pint --test` : réussi.
- `php artisan route:list --except-vendor --no-ansi` : seule route applicative listée, page Laravel `/`. Fichier API vide, préfixe configuré.
- Configuration MySQL : `strict => true` vérifié.
- `npm.cmd run lint` : réussi.
- `npm.cmd run build` : vérification TypeScript et compilation Vite réussies.
- Installations Composer/npm : aucune vulnérabilité signalée par les audits automatiques lors de l'installation.

Versions installées : Laravel 12.72.0, React 19.3.1, Vite 8.3.8, Tailwind 4.3.5, React Router 7.18.5. Fichiers de verrouillage générés.

MySQL 8 et PHP 8.4 exact ne sont pas encore validés. Aucun test de connexion MySQL, de migration ou d'interface dans un navigateur n'a été effectué. La résolution Composer cible PHP 8.4.0, mais les tests PHP ont tourné sous PHP 8.5.12 et Node 24.21.0.

Le dossier initial n'est pas un dépôt Git : aucune comparaison git diff ni revue indépendante n'est possible à ce stade.

## 2026-11-12 — Revue du socle et corrections

Revue du socle par rapport au CDC V2 et à l'architecture, puis corrections sans fonctionnalité métier.

- Références déposées dans `docs/reference/` (CDC V2, présentation, architecture).
- Backend : fuseau `Africa/Casablanca` ; locale `fr` ; sessions en base, 720 min ; file d'attente et cache en base ; tests sur MySQL `jobdating_test` ; `composer.json` renommé ; `phpstan.neon` (Larastan niveau 6) ajouté.
- Backend en API seule : suppression de la page welcome, de `resources/js`, `resources/css`, `package.json` et `vite.config.js` ; le test d'exemple vérifie `/up`.
- Frontend : TypeScript en mode strict ; redirection Vite de `/api`, `/sanctum`, `/up` vers Laravel ; suppression des images par défaut.
- Nettoyage : dossier `Microsoft/` supprimé ; recommandations VS Code remplacées.
- Documentation : `DECISIONS.md` réécrit, `BACKLOG.md` créé, `CURRENT_TASK.md` = T-00, pipeline CI fourni dans `docs/ci.yml`.
- `.env` local aligné sur `.env.example` pour ces mêmes clés (fuseau, locale, sessions, file, cache).

Non exécuté : aucun contrôle n'a été relancé après ces corrections (PHP et réseau indisponibles dans l'environnement de revue). À faire avec T-00 : `composer update --lock`, installation des paquets, puis tous les contrôles. Points d'attention : la vérification TypeScript stricte peut faire apparaître des erreurs ; `php artisan test` exige désormais la base `jobdating_test`.

## 2026-11-12 — Dépôt GitHub et Docker de développement

- Dépôt `alaeloula/jobdating` : branches `main` et `develop` protégées (pull request obligatoire, discussions résolues, approbations annulées si nouveau commit, pas de push direct, de force push ni de suppression, administrateurs inclus). Branche par défaut : `develop`. La règle « CI verte obligatoire » sera ajoutée après la première exécution de la CI.
- Branche locale `feature/T-00-socle` créée.
- `docker-compose.yml` : MySQL 8.0 (port 3307, bases `jobdating` et `jobdating_test`) et Mailpit (SMTP 1025, interface 8025). `.env.example`, `.env` local et `phpunit.xml` alignés. Dans la CI, les variables du job (MySQL sur 3306, utilisateur root) restent prioritaires.
- Non exécuté : `docker compose up` et les contrôles restent à lancer sur le poste (T-00).
