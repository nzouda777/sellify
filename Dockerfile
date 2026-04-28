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

# ---- Frontend assets (Vite) ----
FROM node:20-bullseye-slim AS assets
WORKDIR /app
COPY package*.json vite.config.js ./
COPY resources ./resources
RUN npm install \
    && npm run build

# ---- Final runtime image ----
FROM base AS app
WORKDIR /var/www/html

# Copy application code
COPY --chown=${user}:${user} . .

# Add vendors and built assets from dedicated stages
COPY --chown=${user}:${user} --from=vendor /var/www/html/vendor /var/www/html/vendor
COPY --chown=${user}:${user} --from=assets /app/public/build /var/www/html/public/build

# Permissions for storage/cache
RUN chown -R ${user}:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Setup Nginx and Supervisor for Dokploy (PaaS deployment)
USER root
RUN apt-get update && apt-get install -y nginx supervisor \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Configure Nginx for single-container deployment
RUN echo 'server {\n\
    listen 80;\n\
    index index.php index.html;\n\
    server_name _;\n\
    root /var/www/html/public;\n\
\n\
    location / {\n\
        try_files $uri $uri/ /index.php?$query_string;\n\
    }\n\
\n\
    location ~ \\.php$ {\n\
        include fastcgi_params;\n\
        fastcgi_pass 127.0.0.1:9000;\n\
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;\n\
        fastcgi_param PATH_INFO $fastcgi_path_info;\n\
    }\n\
}' > /etc/nginx/sites-available/default

# Configure Supervisor to run both Nginx and PHP-FPM
RUN echo '[supervisord]\n\
nodaemon=true\n\
user=root\n\
logfile=/dev/null\n\
logfile_maxbytes=0\n\
\n\
[program:php-fpm]\n\
command=php-fpm\n\
user=root\n\
autostart=true\n\
autorestart=true\n\
stdout_logfile=/dev/stdout\n\
stdout_logfile_maxbytes=0\n\
stderr_logfile=/dev/stderr\n\
stderr_logfile_maxbytes=0\n\
\n\
[program:nginx]\n\
command=nginx -g "daemon off;"\n\
user=root\n\
autostart=true\n\
autorestart=true\n\
stdout_logfile=/dev/stdout\n\
stdout_logfile_maxbytes=0\n\
stderr_logfile=/dev/stderr\n\
stderr_logfile_maxbytes=0' > /etc/supervisor/conf.d/supervisord.conf

EXPOSE 80 9000
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
