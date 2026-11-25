# Tâche courante – T-14 (JD-19) : événements, participants et emplacements

Branche à créer avant le code : `feature/T-14-evenements` (base `develop`, `1b05242`).

Plan : `docs/tasks/PLAN-T-14.md` (version 1.4, validée par Codex après corrections mineures).

Périmètre (ADM-05, ADM-07, RG-EVT-01 à 04, RG-PAR-01 à 07, RG-ENTR-03, RG-IMP-06 et 07, ENT-01, APP-00, APP-01, décisions du 23/11) :
- **événements** : un seul par année scolaire, nom terminé automatiquement par l'année (ex. 2026/2027), création, modification, événement courant unique, statut calculé (Africa/Casablanca), archivage, désarchivage, suppression ;
- **apprenants** : inscription automatique des actifs (désignation, création, import, réactivation, restauration) ; un apprenant **retiré reste retiré** jusqu'à « Réinscrire » ;
- **entreprises** : événement **obligatoire** à la création, ajout en masse avec rapport, retrait si aucun passage ;
- **emplacements** (étage, table), facultatifs : administrateur et CME ;
- **affichage** : fiche de connexion, accueil entreprise (**événement courant uniquement**) et « Ma journée » (événement, emplacement, entreprises présentes).

Reporté : programme (**T-15**) ; reprise des anciennes entreprises (**T-16**) ; import des entreprises par fichier (tâche à créer) ; passages et compteurs (**T-30**, **T-33**) ; recherche d'apprenants (**T-23**) ; CV et suggestions (**T-20**, **T-40**).

Données de test : **fictives uniquement**.

Contrôles locaux (MySQL Docker démarré) :

```powershell
# racine du dépôt : tests et style dans Docker, PHP 8.4
docker compose run --rm php php artisan test
docker compose run --rm php vendor/bin/pint --test
docker compose run --rm php vendor/bin/phpstan analyse --memory-limit=1G

# frontend/
npm.cmd run lint
npm.cmd run test
npm.cmd run build
```
