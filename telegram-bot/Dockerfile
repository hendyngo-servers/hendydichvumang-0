FROM composer:2 AS composer

FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libonig-dev libcurl4-openssl-dev unzip ffmpeg tesseract-ocr tesseract-ocr-eng tesseract-ocr-vie \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring curl \
    && printf '%s\n' \
        'upload_max_filesize=205M' \
        'post_max_size=260M' \
        'max_file_uploads=2' \
        'max_execution_time=180' \
        'max_input_time=180' \
        'memory_limit=256M' \
        > /usr/local/etc/php/conf.d/zz-hendy-upload.ini \
    && a2enmod rewrite headers proxy proxy_http proxy_wstunnel \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/app
# Build context is the repository root: shared Website UI is copied outside
# the Bot public DocumentRoot and only read by authenticated PHP routes.
COPY telegram-bot/ /var/www/app/
COPY website/ /var/www/website/
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --prefer-dist --no-interaction --classmap-authoritative --no-progress
RUN composer lint
COPY telegram-bot/docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
RUN chown -R www-data:www-data /var/www/app /var/www/website \
    && chmod -R 755 /var/www/app/public \
    && chmod +x /var/www/app/docker/start.sh

EXPOSE 10000
CMD ["/var/www/app/docker/start.sh"]
