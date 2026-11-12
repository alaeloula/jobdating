# Tâche courante – T-00 : installer les outils et paquets du socle

Ces commandes demandent PHP, Composer et le réseau : elles sont à exécuter sur le poste (PowerShell). Elles n'ajoutent aucune fonctionnalité métier.

## 1. Prérequis

- PHP **8.4** pour ce projet (Herd : sélectionner PHP 8.4 pour le dossier).
- Docker Desktop démarré, puis depuis la racine :

```powershell
docker compose up -d
docker compose ps        # mysql doit être « healthy »
```

MySQL 8 écoute sur le port **3307** avec les bases `jobdating` et `jobdating_test` (utilisateur `jobdating`, valeurs de développement déjà dans `.env.example` et `phpunit.xml`). Mailpit affiche les e-mails envoyés sur http://localhost:8025.

## 2. Backend (depuis `backend/`)

```powershell
composer update --lock
composer require laravel/sanctum spatie/laravel-permission
composer require --dev larastan/larastan
php artisan install:api
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan lang:publish
php artisan migrate
```

- `composer update --lock` : met à jour le verrou après le renommage du paquet dans `composer.json`.
- `install:api` : installe Sanctum. Ne pas utiliser de jetons d'accès : le mode retenu est la session par cookie (CDC 9.5). Vérifier que `routes/api.php` garde le préfixe `/api/v1`.
- `lang:publish` : crée `lang/` ; les traductions françaises seront ajoutées dans `lang/fr/`.

## 3. Frontend (depuis `frontend/`)

```powershell
npm.cmd install -D vitest @testing-library/react @testing-library/jest-dom jsdom
npm.cmd pkg set scripts.test="vitest run --passWithNoTests"
```

## 4. Contrôles à exécuter et à reporter dans `PROGRESS.md`

```powershell
# backend/
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test
composer audit

# frontend/
npm.cmd run lint
npm.cmd run build      # vérifie aussi TypeScript, désormais en mode strict
npm.cmd run test
npm.cmd audit --audit-level=high
```

## 5. Intégration continue (même branche `feature/T-00-socle`)

Déplacer `docs/ci.yml` vers `.github/workflows/ci.yml` (dossier protégé : à faire sur le poste).

```powershell
New-Item -ItemType Directory -Force .github/workflows
Move-Item docs/ci.yml .github/workflows/ci.yml
```

## 6. Git (branche `feature/T-00-socle` déjà créée)

```powershell
git add .
git commit -m "T-00 : outils du socle, Docker de développement et pipeline CI"
git push -u origin feature/T-00-socle
```

Vérifier avant le commit que `git status` ne liste ni `.env`, ni `vendor/`, ni `node_modules/`, ni `dist/`. Ouvrir ensuite la pull request vers `develop` : la CI se lance automatiquement.

Statut : à faire.
