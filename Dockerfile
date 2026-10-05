FROM php:8.4-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip curl poppler-utils tesseract-ocr chromium libzip-dev libicu-dev libonig-dev libsqlite3-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql pdo_sqlite mbstring intl zip dom \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY deploy /tmp/deploy

RUN cat /tmp/deploy/payload.part1 /tmp/deploy/payload.part2 /tmp/deploy/payload.part3 /tmp/deploy/payload.part4 \
    | base64 -d | tar xz -C /app \
    && cp -f /tmp/deploy/overrides/config/*.php /app/config/ \
    && cp -f /tmp/deploy/overrides/composer.json /app/composer.json \
    && cp -f /tmp/deploy/overrides/routes/web.php /app/routes/web.php \
    && cp -f /tmp/deploy/overrides/bootstrap/app.php /app/bootstrap/app.php \
    && cp -f /tmp/deploy/overrides/bootstrap/providers.php /app/bootstrap/providers.php \
    && cp -rf /tmp/deploy/overrides/app/* /app/app/ \
    && cp -rf /tmp/deploy/overrides/resources/views/* /app/resources/views/ \
    && mkdir -p /app/bin /app/data \
    && cp -rf /tmp/deploy/overrides/bin/* /app/bin/ \
    && cp -rf /tmp/deploy/overrides/data/* /app/data/ \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

ENV APP_ENV=production
ENV APP_DEBUG=false
ENV LOG_CHANNEL=stderr
ENV CHROME_BINARY=/usr/bin/chromium
ENV CRAWLER_USER_AGENT="Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36 LatestGovtJobsCrawler/1.0"

EXPOSE 8080

CMD sh -c 'if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then mkdir -p "$(dirname "${DB_DATABASE:-/data/database.sqlite}")" && touch "${DB_DATABASE:-/data/database.sqlite}"; fi; php artisan migrate --force && php /app/bin/bootstrap-production.php && php /app/bin/import-recruitment-directory.php && php artisan optimize:clear && php /app/bin/crawler-daemon.php >> /app/storage/logs/crawler-daemon.log 2>&1 & exec php artisan serve --host=0.0.0.0 --port=${PORT:-8080}'