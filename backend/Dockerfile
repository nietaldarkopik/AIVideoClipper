# syntax=docker/dockerfile:1

# ---- Stage 1: PHP dependencies ------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction \
    --ignore-platform-reqs

# ---- Stage 2: runtime ---------------------------------------------------------
FROM php:8.4-cli-bookworm

# ffmpeg/ffprobe: rendering. postgresql-client: pg_dump/psql for backup:* commands.
# fonts-dejavu-core: a real .ttf for burned-in captions (see DEFAULT_FONT_FILE).
RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg postgresql-client tar unzip curl ca-certificates \
        python3 python3-venv fonts-dejavu-core \
        libpq-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libicu-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql gd zip pcntl bcmath intl exif opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# yt-dlp with curl_cffi, which the downloader's `--impersonate chrome` needs.
RUN python3 -m venv /opt/ytdlp \
    && /opt/ytdlp/bin/pip install --no-cache-dir "yt-dlp[default,curl-cffi]" \
    && ln -s /opt/ytdlp/bin/yt-dlp /usr/local/bin/yt-dlp

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=vendor /usr/bin/composer /usr/bin/composer

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
        storage/logs storage/app/media storage/app/backups bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-interaction \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

USER www-data
EXPOSE 8000
ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
