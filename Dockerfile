# hr-backend — staging image (ADR-0007: Laravel owns the schema/migrations;
# hr-ai never migrates).
#
# Two-stage build: vendor deps compiled in a separate stage from the app copy
# so the composer.json/lock layer caches independently of app-code edits.
# Runtime stage runs php-fpm + nginx together via supervisord (simplest given
# no existing Octane setup in composer.json) — Caddy (a separate compose
# service) reverse-proxies HTTP to this container's :80.
#
# The SAME image is reused, UNMODIFIED, as the `hr-backend-worker` service in
# docker-compose.staging.yml — only the container `command` differs
# (supervisord/php-fpm+nginx here vs `php artisan queue:work` there). No
# secret is ever baked in: real config arrives at container start via the
# shared SSM entrypoint (hr-docs/infra/compose/entrypoint.sh, bind-mounted by
# compose, using the EC2 instance profile's credential chain — nothing here).

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer install --no-dev --no-scripts --optimize-autoloader --prefer-dist \
 && composer dump-autoload --no-dev --optimize

FROM php:8.3-fpm AS runtime

# awscli: used by the shared SSM entrypoint script (bind-mounted at deploy
# time) to resolve SecureString parameters via the instance profile.
RUN apt-get update && apt-get install -y --no-install-recommends \
      nginx supervisor libpq-dev libzip-dev libicu-dev unzip awscli curl \
    && docker-php-ext-install pdo_pgsql pgsql zip intl bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www

COPY --from=vendor /app /var/www

COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

EXPOSE 80

# Overridden by docker-compose.staging.yml for every service (web, worker) so
# the SSM entrypoint always runs first — this CMD only matters for a bare
# `docker run` outside compose.
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
