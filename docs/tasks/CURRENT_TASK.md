# Tâche courante – T-13 (JD-18) : import des apprenants (fichier YouCode School Admin)

Branche : `feature/T-13-import` (base `develop`, `81f9d02`).

Plan : `docs/tasks/PLAN-T-13.md` (version 2, en contre-revue par Codex avant le code).

Périmètre (ADM-04 import des apprenants, RG-IMP-01 à 07, EXI-DATA-03, décisions du 14/11, 19/11 et 20/11) :
- lecture du fichier exporté de YouCode School Admin, **CSV ou Excel** (en-têtes en ligne 1, ou en ligne 2 après un titre) ; colonnes ignorées jamais enregistrées ;
- choix du **campus et de la promotion** ; **classe lue sur chaque ligne** (colonne `Classe`, classes actives des référentiels ; classe inconnue → ligne rejetée) ; **aperçu** : à créer, à mettre à jour, inchangés, rejetés (motifs) ;
- **confirmation** avec relecture du fichier et recomptage (409 si l'aperçu a changé) ; **traitement en file d'attente**, tout ou rien ;
- création (e-mail seulement pour les actifs, après la validation finale) et mise à jour des apprenants connus (mot de passe, passages, CV et compétences inchangés ; liens annulés si l'e-mail change) ;
- **fichier des mots de passe** (Excel en clair à télécharger, copie chiffrée sur le serveur) téléchargeable plusieurs fois jusqu'à sa suppression ; historique et rapport des imports ;
- dépendance **OpenSpout** (accord du responsable technique).

Reporté : import des entreprises (**tâche séparée**) ; inscription à l'événement (**T-14**) ; CV (**T-20 à T-22**).

Données de test : **fictives uniquement** (fichier réel fourni pour le format, jamais copié ni utilisé).

Contrôles locaux (MySQL Docker démarré) :

```powershell
# racine du dépôt : tests et style dans Docker, PHP 8.4
docker compose run --rm php php artisan test
docker compose run --rm php vendor/bin/pint --test
docker compose run --rm php vendor/bin/phpstan analyse --memory-limit=1G

# backend/ (PHP sous Windows)
php artisan migrate
docker compose run --rm php php artisan queue:work database-imports --queue=imports --timeout=660   # imports (pcntl)
php artisan queue:work database --queue=default   # e-mails

# frontend/
npm.cmd run lint
npm.cmd run test
npm.cmd run build
```
