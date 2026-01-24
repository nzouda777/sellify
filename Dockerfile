# syntax=docker/dockerfile:1.6

FROM php:8.2-fpm-bullseye AS base

ARG user=laravel
ARG uid=1000

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/composer

# Install system dependencies and PHP extensions needed by Laravel/Filament
RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    libicu-dev \
    libjpeg-dev \
    libfreetype6-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure zip \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_mysql intl mbstring exif pcntl bcmath gd zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/local/bin/composer

# Create non-root user
RUN useradd -G www-data,root -u "${uid}" -d /home/${user} ${user} \
    && mkdir -p /home/${user}/.composer \
    && chown -R ${user}:${user} /home/${user}

WORKDIR /var/www/html

# ---- PHP dependencies (composer) ----
FROM base AS vendor
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts


# Add vendors and built assets from dedicated stages
COPY --chown=${user}:${user} --from=vendor /var/www/html/vendor /var/www/html/vendor
COPY --chown=${user}:${user} --from=assets /app/public/build /var/www/html/public/build

# Permissions for storage/cache
RUN chown -R ${user}:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
# RUN chmod +x /usr/local/bin/docker-entrypoint.sh


USER ${user}

EXPOSE 10000

CMD ["/usr/local/bin/docker-entrypoint.sh"]