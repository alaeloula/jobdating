# Tâche courante – T-03 (JD-10) : rôles et permissions

Branche : `feature/T-03-roles-permissions`.

Périmètre validé :
- 5 rôles et 25 permissions (une par ligne de la matrice CDC 3.2) ;
- administrateur : permissions attribuées explicitement, pas de règle « peut tout » ;
- un seul rôle par compte (RG-ACC-06) ;
- seeder idempotent, sans compte de test (EXI-SEC-11) ;
- test de chaque cellule de la matrice.

Hors périmètre : policies de périmètre (🔸), routes et écrans (T-04 et suivantes).

Contrôles locaux (depuis `backend/`, MySQL Docker démarré) :

```powershell
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test
php artisan migrate:fresh --seed   # base de développement : rôles et permissions créés
```

Tâche suivante : T-04 (JD-11) – connexion.
