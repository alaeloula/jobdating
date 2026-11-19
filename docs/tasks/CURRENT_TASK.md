# Tâche courante – T-11 (JD-16) : entreprises

Branche : `feature/T-11-entreprises`.

Plan validé : `docs/tasks/PLAN-T-11.md` (version 2, après la revue du plan par Codex ; pas de contre-revue du plan, les points [v2] sont à vérifier dans la revue du code).

Périmètre livré (ADM-02, RG-ENTR-01 à 08, ENT-08 partiel, décisions du 16/11, 17/11 et 19/11) :
- fiche entreprise (nom, secteur facultatif, description, site web, logo, contact) ; compte entreprise activé, nom d'utilisateur proposé puis définitif, mot de passe généré affiché une seule fois sur la fiche de connexion imprimable ; aucun e-mail ;
- confirmation d'impression liée à la version des identifiants ; réinitialisation (toutes les sessions fermées) ; désactivation / réactivation ; pas de suppression ;
- profils recherchés (filières et compétences) avec le bandeau d'avertissement, par l'administrateur et la CME ; recruteurs en consultation ;
- l'entreprise modifie sa description et son site web depuis « Mon profil ».

Reporté : événement, emplacement, « Retirer », éditions précédentes (**T-14**) ; import (**T-13**) ; export (**T-61**) ; création des recruteurs (**T-31**) ; suggestions aux apprenants (**T-40**).

Contrôles locaux (MySQL Docker démarré) :

```powershell
# racine du dépôt : tests et style dans Docker, PHP 8.4
docker compose run --rm php php artisan test
docker compose run --rm php vendor/bin/pint --test

# backend/ (PHP sous Windows)
vendor/bin/phpstan analyse
php artisan migrate
php artisan db:seed --class=DemoAccountsSeeder   # local : fiche « Entreprise Démo »

# frontend/
npm.cmd run lint
npm.cmd run test
npm.cmd run build
```

Tâche suivante : T-12 (JD-17) – apprenants.
