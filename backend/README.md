# Backend JobDating

Laravel 12, cible PHP 8.4 et MySQL 8. Composer résout les dépendances pour PHP 8.4.0 via config.platform.php. Le runtime local utilisé lors de l'initialisation est PHP 8.5.12 ; une vérification avec PHP 8.4 exact reste nécessaire.

Depuis backend/ :

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan serve
```

Ne pas écraser un .env déjà configuré. En développement, MySQL 8 et Mailpit sont fournis par Docker : `docker compose up -d` depuis la racine du dépôt (MySQL sur le port 3307, bases `jobdating` et `jobdating_test` ; e-mails visibles sur http://localhost:8025). Exécuter ensuite `php artisan migrate`. Aucune migration n'a été exécutée pendant l'initialisation. Le mode strict MySQL Laravel reste activé.

Sessions (12 h), cache et file d'attente utilisent la base de données : exécuter `php artisan migrate`, puis `php artisan queue:work` pour traiter les jobs. Fuseau horaire : Africa/Casablanca. Les tests utilisent la base MySQL `jobdating_test` (voir `phpunit.xml`). Le backend est une API seule : aucune page web, la sonde `/up` répond. Stockage local privé par défaut. Aucune route métier : routes/api.php est préparé avec le préfixe /api/v1 ; /up est la sonde Laravel. Sanctum cookies/CSRF, Spatie Permission et les policies devront être configurés avec leurs tests dans des tâches ultérieures.

Contrôles : `composer validate --strict`, `php artisan test`, `php vendor/bin/pint --test`.

Documentation officielle : https://laravel.com/docs/12.x/installation
