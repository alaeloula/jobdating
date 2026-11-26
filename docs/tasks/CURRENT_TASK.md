# Tâche courante – T-64 (JD-44) : import des entreprises par fichier

Branche à créer avant le code : `feature/T-64-import-entreprises` (base `develop`, `c3f0365`).

Plan : `docs/tasks/PLAN-T-64.md` (version 1.3, développement en cours).

Périmètre (ADM-04 « Import des entreprises », RG-ENTR-01 à 07, RG-PAR-01 et 07, décision du 25/11) :
- lecture du **classeur de suivi de l'équipe CME tel quel** : feuille « Entreprises Invitées », lignes « Confirmation par mail » = « Oui », **nom seul** ;
- aperçu (à créer, déjà existantes, doublons, rejetées, non confirmées), confirmation, traitement en arrière-plan, rapport, historique ;
- comptes créés et affectés à l'événement choisi ; **fiches de connexion imprimables en une fois**, mots de passe chiffrés jusqu'à leur suppression ;
- aucune autre colonne lue ni enregistrée ; aucun e-mail.

Reporté : feuille « Informations entreprises Appli » ; mise à jour des entreprises existantes ; reprise de l'ancienne application (T-16, abandonnée).

Données de test : **classeurs fictifs uniquement** (aucun fichier réel du dossier Téléchargements).

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
