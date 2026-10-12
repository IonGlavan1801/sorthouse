FROM node:24-alpine AS assets
WORKDIR /build
COPY package*.json .npmrc ./
RUN npm ci
COPY resources resources
COPY vite.config.js .
RUN npm run build

FROM php:8.4-fpm-alpine AS app
RUN apk add --no-cache postgresql-dev icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install pdo_pgsql intl pcntl bcmath zip opcache gd \
    && pecl install redis >/dev/null && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=assets /build/public/build public/build
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
COPY docker/php.ini /usr/local/etc/php/conf.d/sorthouse.ini
USER www-data
CMD ["php-fpm"]

FROM nginx:1.28-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --chown=nginx:nginx --from=app /var/www/html/public /var/www/html/public
