# Tâche courante – T-02 (JD-9) : référentiels

Branche : `feature/T-02-referentiels`.

Plan validé : `docs/tasks/PLAN-T-02.md` (version 3, après la revue et la contre-revue du plan par Codex).

Périmètre livré :
- 8 référentiels : filières, classes, campus, promotions, secteurs, **compétences**, types d'opportunité, étiquettes d'évaluation (tables `filieres`, `classes`, `campuses`, `promotions`, `sectors`, `competences`, `opportunity_types`, `evaluation_tags_ref`) ;
- gestion par l'administrateur et l'équipe CME (`referentials.manage`) : liste, ajout, modification, désactivation / réactivation, ordre (opportunités ; étiquettes par type) ; **aucune suppression** ;
- lecture des options pour tout utilisateur connecté (actives ; `?inactives=1` avec leur état) ;
- règles : code de filière définitif ; filière d'une classe utilisée modifiable **avec confirmation** (`confirmer_changement_filiere: true`, sinon 409 avec le nombre d'apprenants) ; type d'une étiquette utilisée non modifiable ; contrôles faits sous verrou de la ligne ;
- journal d'audit : catégorie « Référentiels » ;
- données initiales : opportunités (PC-23) et étiquettes (ENT-05) avec clés fixes ; autres listes vides ;
- écran « Référentiels » (maquette `admin-12`) et lien dans l'en-tête.

Points d'extension à brancher obligatoirement :
- **T-12** : `App\Contracts\ClassUsage` (nombre d'apprenants d'une classe), et verrou de la ligne de classe à la première affectation d'un apprenant (aussi T-13) ;
- **T-31** : `App\Contracts\TagUsage` (étiquette utilisée), et verrou de la ligne d'étiquette à l'association avec une évaluation.

Hors périmètre : utilisation des listes dans les formulaires et filtres (T-11, T-12, T-13, T-31, T-40) ; compétences recherchées (T-11) et compétences des apprenants (T-12) ; saisie en masse (COMP-01).

Contrôles locaux (MySQL Docker démarré) :

```powershell
# backend/
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test
php artisan migrate
php artisan db:seed --class=ReferentialsSeeder

# frontend/
npm.cmd run lint
npm.cmd run test
npm.cmd run build
```

Tâche suivante : T-05 (JD-12) – activation, oubli et changement du mot de passe.
