# PHP 8.4 pour lancer les tests et les outils du backend en local (ENV-01).
# Même version et mêmes extensions que la CI (.github/workflows : mbstring, pdo_mysql, intl, zip).
# Ne sert pas en production.
FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev unzip git \
    && docker-php-ext-install pdo_mysql intl zip \
    && rm -rf /var/lib/apt/lists/*

# Cache des scripts PHP en ligne de commande : le dossier partagé avec Windows est lent à lire
# (≈ 7 ms par accès). Chaque « docker compose run » lance un nouveau processus, donc le cache ne
# peut pas servir du code périmé : inutile de revérifier les fichiers pendant l'exécution.
RUN docker-php-ext-enable opcache \
    && { \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'realpath_cache_size=4096K'; \
        echo 'realpath_cache_ttl=600'; \
        echo 'memory_limit=1G'; \
    } > /usr/local/etc/php/conf.d/jobdating.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
