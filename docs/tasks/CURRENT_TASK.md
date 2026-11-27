# Tâche courante – T-20 (JD-22) : téléversement et remplacement du CV

Branche : `feature/T-20-cv` (base `develop`, `2f8a9d8`).

Plan : `docs/tasks/PLAN-T-20.md` (version 1.2 : revue et contre-revue Codex traitées ; décisions 1 à 11).

## Périmètre proposé (CV-01, CV-04, RG-CV-05, RG-CV-06, RG-APR-03, CDC 7.2, 7.5, 7.6, 8.7, 11.6)

- **Téléverser ou remplacer** le CV d'un apprenant depuis sa fiche : administrateur et équipe CME seulement (permission `cv.upload`, contrôlée côté serveur) ;
- **PDF uniquement**, type réel contrôlé (signature du fichier, pas seulement l'extension) et taille maximale ;
- **un seul CV par apprenant** (`cv_documents.learner_id` unique) : le nouveau remplace l'ancien, dont le fichier est supprimé définitivement ;
- **stockage privé, contenu chiffré** avec la clé de l'application (ARCHITECTURE 7) ; empreinte SHA-256 et taille enregistrées ;
- **historique des ajouts (CV-04)** : apprenant, source (téléversé / plateforme CME), auteur, date, résultat ; consultable par l'administrateur et l'équipe CME (permission `cv.history`) ;
- **état du CV sur la fiche** de l'apprenant (ADM-03) : présent ou absent, source, date et auteur de l'ajout ;
- **retrait du CV** sans remplacement (DECISIONS 26/11) ;
- journal d'audit de chaque ajout, remplacement ou retrait.

## Hors périmètre (tâches suivantes)

- consultation et téléchargement du PDF par lien temporaire, journal des consultations : **T-21** ;
- import depuis la plateforme CME : **T-22** (la colonne `source` et l'historique sont prévus dès T-20) ;
- écran « État de mon CV » de l'apprenant (APP-03, avec aperçu) et affichage côté entreprise : **T-21, T-23, T-24**.

## Points tranchés le 26/11

1. **Taille maximale : 5 Mo** (DECISIONS 26/11).
2. **« historique » = journal des ajouts** (CV-04) ; une seule version du fichier est conservée (CDC 8.7).
3. **« Retirer le CV »** ajouté : administrateur et équipe CME, après confirmation, fichier supprimé définitivement, inscrit dans l'historique et le journal d'audit (DECISIONS 26/11).
4. **Affichage** : état du CV (sans aperçu) sur la fiche admin/CME dans T-20 ; aperçu, lien temporaire et écran apprenant avec T-21.

## Données de test

PDF **fictifs** générés par les tests ; aucun vrai CV dans le dépôt ni dans les tests automatiques. Exports fictifs (Word, LibreOffice, Canva, navigateur) fournis par le responsable technique dans `backend/tests/Fixtures/cv/`, sans aucune donnée réelle.

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
