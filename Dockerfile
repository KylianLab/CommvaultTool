# TapeTrack - PHP 8.4 CLI image.
#   target "prod": app:commvault:discover (and later the sync worker)
#   target "dev" : + dev dependencies, to run the test suite
FROM php:8.4-cli-bookworm AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip ca-certificates libicu-dev libzip-dev \
    && docker-php-ext-install intl zip opcache pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Same UID as the default WSL user, so files written to bind mounts
# (var/discovery, var/log) belong to you on the host.
ARG UID=1000
RUN useradd --create-home --uid ${UID} app

WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

COPY composer.json composer.lock symfony.lock ./

FROM base AS dev
ENV APP_ENV=test
RUN composer install --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload && mkdir -p var && chown app:app /app && chown -R app:app var
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
USER app
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["vendor/bin/phpunit"]

FROM base AS prod
ENV APP_ENV=prod \
    APP_DEBUG=0
RUN composer install --prefer-dist --no-scripts --no-autoloader --no-dev
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && mkdir -p var/discovery var/log var/commvault \
    && chown -R app:app var
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
USER app
ENTRYPOINT ["/usr/local/bin/entrypoint.sh", "php", "bin/console"]
CMD ["list", "app"]
