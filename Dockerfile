FROM node:22-bookworm-slim AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

FROM php:8.5-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath mbstring pdo_pgsql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV PORT=10000 \
    APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=pgsql \
    SESSION_DRIVER=database \
    SESSION_SECURE_COOKIE=true \
    CACHE_STORE=file \
    QUEUE_CONNECTION=sync \
    LOG_CHANNEL=stderr \
    MAIL_MAILER=log

WORKDIR /var/www/html

COPY . .

RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/cache/locks storage/framework/sessions storage/framework/views storage/logs \
    && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache

COPY --from=frontend /app/public/build ./public/build
COPY render-apache.conf /etc/apache2/sites-available/000-default.conf
COPY render-start.sh /usr/local/bin/render-start

RUN printf 'Listen ${PORT}\n' > /etc/apache2/ports.conf \
    && chmod +x /usr/local/bin/render-start

EXPOSE 10000

ENTRYPOINT ["render-start"]
CMD ["apache2-foreground"]
