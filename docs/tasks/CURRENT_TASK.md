# Tâche courante – T-04 (JD-11) : connexion

Branche : `feature/T-04-connexion`.

Périmètre validé (14/11/2026) :
- table `users` alignée sur le CDC 8.2 (prénom, nom, nom d'utilisateur, e-mail facultatif, téléphone, activé_le, dernière_connexion, désactivé_le, suppression logique) ;
- Sanctum en session par cookie + CSRF ; `POST /api/v1/login` (e-mail ou nom d'utilisateur), `POST /api/v1/logout`, `GET /api/v1/me` ;
- message générique ; comptes désactivés, non activés, supprimés ou sans rôle refusés ;
- RG-AUTH-01 : 5 tentatives/min par identifiant + IP, 100/min par IP, HTTP 429 avec délai ;
- RG-AUTH-02 : session de 12 h maximum depuis la connexion ; RG-ACC-05 : session perdue dès la désactivation ;
- accueil par rôle : pilotage (administrateur, CME, Direction en lecture seule), entreprise, « Ma journée » ;
- comptes de démonstration fictifs, développement local uniquement ;
- frontend : client API (cookie, CSRF, 401, 403, 419), gardes de route, mise en page avec déconnexion, écran « Connexion » (maquette `00-connexion`).

Décisions du 14/11 (`DECISIONS.md`) : pas de changement de mot de passe obligatoire à la première connexion de l'apprenant ; TanStack Query, React Hook Form, Zod (+ `@hookform/resolvers`) ajoutés.

Hors périmètre : activation, oubli et changement du mot de passe, renvoi du lien d'activation, lien « Mot de passe oublié ? » (T-05) ; réinitialisation par l'administrateur (T-10, T-11, T-12) ; journalisation des connexions (T-06).

Contrôles locaux (MySQL Docker démarré) :

```powershell
# backend/
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test
php artisan migrate
php artisan db:seed --class=DemoAccountsSeeder   # comptes fictifs, APP_ENV=local uniquement
php artisan serve
# Si « Unable to start curl module » (constaté le 14/11 avec PHP 8.5 de Herd), depuis backend/public :
# php -S 127.0.0.1:8000 ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php

# frontend/
npm.cmd run lint
npm.cmd run test
npm.cmd run build
npm.cmd run dev   # http://localhost:5173
```

Tâche suivante : T-06 (JD-13) – journal d'audit.
