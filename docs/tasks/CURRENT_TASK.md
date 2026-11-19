# Tâche courante – T-10 (JD-15) : comptes de l'équipe (CME, Direction, administrateurs)

Branche : `feature/T-10-utilisateurs`.

Mode : pas de revue du plan (choix du responsable technique, 18/11) ; une revue du code par Codex.

Périmètre livré (ADM-01, RG-USR-01 à 05, RG-ACC-05 / 06, décisions du 14/11 et du 18/11) :
- liste des comptes CME, Direction et administrateur (recherche, filtres rôle et statut, pagination) ; statuts « Actif », « Invitation envoyée », « Désactivé », « Supprimé » ;
- création avec lien d'activation, modification (rôle unique), désactivation / réactivation, suppression logique / restauration, renvoi du lien, réinitialisation du mot de passe par l'administrateur (affiché une fois et envoyé par e-mail) ;
- protections : ni suppression, ni désactivation, ni changement de rôle de son propre compte ; toujours au moins un administrateur actif ;
- écran « Comptes CME et Direction » (`/comptes`, maquette admin-10).

Contrats pour les tâches suivantes :
- **T-11 / T-12** : `App\Services\Passwords\PasswordGenerator::generate()` pour les mots de passe générés ; réinitialisation : même schéma que `StaffAccountService::resetPassword()` (révoquer les liens, fermer les sessions, journaliser avec l'administrateur comme auteur) ; pas d'e-mail pour une entreprise (décision du 14/11).

Contrôles locaux (MySQL Docker démarré) :

```powershell
# racine du dépôt : tests et style dans Docker, PHP 8.4
docker compose run --rm php php artisan test
docker compose run --rm php vendor/bin/pint --test

# backend/ (PHP sous Windows)
vendor/bin/phpstan analyse
php artisan queue:work   # pour recevoir les e-mails dans Mailpit (http://localhost:8025)

# frontend/
npm.cmd run lint
npm.cmd run test
npm.cmd run build
```

Tâche suivante : T-11 (JD-16) – entreprises.
