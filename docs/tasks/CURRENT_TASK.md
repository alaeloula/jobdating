# Tâche courante – T-12 (JD-17) : apprenants

Branche : `feature/T-12-apprenants` (base `develop`, `d6cb56f`).

Plan : `docs/tasks/PLAN-T-12.md` (version 1, en revue par Codex avant le code).

Périmètre (ADM-03, RG-APR-01 à 07, décisions du 14/11, 16/11 et 19/11) :
- fiche apprenant (CNIE, identité, téléphone, campus, promotion, classe, filière déduite, identifiant CME) ; ajout à la main avec mot de passe généré envoyé par e-mail et affiché une fois ;
- modification, réinitialisation du mot de passe, désactivation / réactivation, suppression logique / restauration (administrateur) ;
- désactivation en masse avec recomptage serveur ; ajout d'une compétence à une sélection ;
- compétences des apprenants (administrateur et CME, sans niveau) ;
- CNIE réservée à l'administrateur, masquée dans la liste, consultation journalisée ;
- liste en lecture seule pour la CME et la Direction.

Reporté : inscription à l'événement (**T-14**) ; import (**T-13**) ; CV (**T-20**) ; liste côté entreprise (**T-23**) ; suggestions (**T-40**) ; export (**T-61**).

Contrôles locaux (MySQL Docker démarré) :

```powershell
# racine du dépôt : tests et style dans Docker, PHP 8.4
docker compose run --rm php php artisan test
docker compose run --rm php vendor/bin/pint --test
docker compose run --rm php vendor/bin/phpstan analyse --memory-limit=1G

# backend/ (PHP sous Windows)
php artisan migrate
php artisan db:seed --class=DemoAccountsSeeder

# frontend/
npm.cmd run lint
npm.cmd run test
npm.cmd run build
```
