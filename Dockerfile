FROM php:8.3-fpm-alpine

WORKDIR /var/www/html

RUN apk add --no-cache \
        acl \
        bash \
        git \
        icu-dev \
        libzip-dev \
        mysql-client \
        oniguruma-dev \
        unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pdo_mysql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del --no-cache .build-deps oniguruma-dev

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN { \
        echo 'expose_php = Off'; \
        echo 'memory_limit = 256M'; \
        echo 'max_execution_time = 60'; \
        echo 'max_input_time = 60'; \
        echo 'post_max_size = 20M'; \
        echo 'upload_max_filesize = 20M'; \
        echo 'realpath_cache_size = 4096K'; \
        echo 'realpath_cache_ttl = 600'; \
        echo 'session.cookie_secure = 1'; \
        echo 'session.cookie_httponly = 1'; \
        echo 'session.cookie_samesite = Lax'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.enable_cli = 1'; \
        echo 'opcache.memory_consumption = 256'; \
        echo 'opcache.interned_strings_buffer = 16'; \
        echo 'opcache.max_accelerated_files = 20000'; \
        echo 'opcache.validate_timestamps = 0'; \
        echo 'opcache.preload = /var/www/html/config/preload.php'; \
        echo 'opcache.preload_user = www-data'; \
    } > /usr/local/etc/php/conf.d/zz-production.ini

COPY . .

RUN if [ -f composer.json ]; then \
        composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader; \
    fi \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

USER www-data

CMD ["php-fpm"]
