# Tâche courante – T-21 (JD-23) : consultation du CV par lien temporaire, journalisée

Branche : `feature/T-21-consultation-cv` (base `develop`, `eab9845`).

Plan : `docs/tasks/PLAN-T-21.md` (version 1.1 : revue Codex traitée ; décisions 1 à 10).

## Périmètre (CV-03, RG-CV-01 à 03, APP-03, CDC 7.5, 7.6, 11.6, matrice 3.2)

- **Afficher le CV dans le site** (aperçu intégré, « Ouvrir en plein écran ») et **le télécharger** ;
- **lien temporaire** de **15 minutes**, généré après contrôle des droits, lié au compte qui l'a demandé, valable pour la **version courante** seulement (RG-CV-01) ;
- **journal** de chaque consultation et téléchargement dans le **journal d'audit** (catégorie « CV ») : utilisateur, apprenant, version, action, date, adresse IP ;
- rôles : **administrateur, CME, Direction** (tous les apprenants visibles ; apprenant supprimé : administrateur seulement) et **apprenant** pour **son** CV (écran « Mon CV », maquette `apprenant-04`) ;
- protection du document servi (suite de la décision 9 révisée de T-20) : en-têtes de sécurité, aucune mise en cache.

## Hors périmètre

- **Entreprise** : accès au CV, périmètre RG-ENT-01 et fiche apprenant côté entreprise → **T-24** (réponse du 27/11) ;
- import depuis la plateforme CME → T-22.

## Points tranchés le 27/11

1. Le CV s'**affiche dans le site**, dessiné par **pdf.js** (dépendance `pdfjs-dist` acceptée le 27/11 après la revue du plan) : le contenu du PDF n'est jamais exécuté.
2. Entreprise : **tout en T-24**.
3. Journal : **journal d'audit** existant (écart de nom avec `cv_access_logs` du CDC 8, à signaler).
4. Lien temporaire : **15 minutes**.
5. Numéro de **version** renouvelé à chaque remplacement : un ancien lien ne remarche jamais.
6. CV d'un apprenant **désactivé** : consultable par l'administrateur, la CME et la Direction.

## Données de test

PDF **fictifs** uniquement (`tests/Support/FakePdf.php`, `tests/Fixtures/cv/`).

## Contrôles locaux (MySQL Docker démarré ; une seule suite backend à la fois)

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
