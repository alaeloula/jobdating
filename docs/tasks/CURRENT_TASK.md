# Tâche courante – T-06 (JD-13) : journal d'audit

Branche : `feature/T-06-journal-audit`.

Périmètre validé (16/11/2026) :
- table `audit_logs` en ajout seul : auteur (et son libellé recopié), action, catégorie, élément concerné (et son libellé), valeurs avant et après, adresse IP, date ;
- non modifiable : le modèle refuse modification et suppression ; des déclencheurs MySQL refusent toute modification et toute suppression de ligne de moins d'un an ;
- service unique `App\Services\AuditLogger` : jamais de mot de passe, jeton ou secret ; CNIE masquée ;
- événements branchés : création, modification, désactivation, réactivation, suppression et restauration de comptes ; changements de rôle ; connexions réussies et refusées (avec motif), blocages (une fois par blocage), déconnexions ;
- purge : `php artisan audit:purge`, planifiée chaque jour à 3 h ; supprime uniquement les lignes du journal d'audit de plus d'un an ;
- API `GET /api/v1/audit-logs` et `GET /api/v1/audit-logs/filters` (permission `audit.view`, administrateur) : filtres catégorie, auteur, période, élément (historique complet d'un élément) ; 50 lignes par page, 100 au plus ;
- écran « Journal d'audit » (maquette `admin-13`) : filtres par catégorie, auteur et période, tableau, détail avant / après, pagination ; lien dans l'en-tête pour l'administrateur.

Hors périmètre : bouton « Exporter » (T-61) ; journalisation des passages, évaluations, CV, imports, exports (branchée dans leurs tâches avec `AuditLogger`) ; exécution du planificateur sur le serveur (T-01).

Prérequis local : conteneur MySQL relancé avec l'option `--log-bin-trust-function-creators=1` (`docker-compose.yml`), sinon la migration échoue (erreur 1419).

Contrôles locaux (MySQL Docker démarré) :

```powershell
# backend/
docker compose up -d mysql      # depuis la racine, après la modification de docker-compose.yml
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test
php artisan migrate
php artisan audit:purge

# frontend/
npm.cmd run lint
npm.cmd run test
npm.cmd run build
```

Tâche suivante : T-02 (JD-9) – référentiels.
