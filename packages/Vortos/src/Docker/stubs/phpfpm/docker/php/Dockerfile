# ============================================================
# Stage 1 — Base: shared PHP + extensions layer
# ============================================================
FROM php:8.3-fpm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip \
    libpq-dev \
    librdkafka-dev \
    librabbitmq-dev \
    && rm -rf /var/lib/apt/lists/*

RUN pecl install redis mongodb rdkafka amqp \
    && docker-php-ext-enable redis mongodb rdkafka amqp \
    && docker-php-ext-install pdo pdo_pgsql pcntl

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

RUN mkdir -p /var/www/html/var/log /var/www/html/var/cache \
    && chmod -R 777 /var/www/html/var

# ============================================================
# Stage 2 — Worker: supervisord target
# ============================================================
FROM base AS worker

RUN apt-get update && apt-get install -y --no-install-recommends supervisor \
    && rm -rf /var/lib/apt/lists/*

RUN mkdir -p /var/log/supervisor

COPY docker/worker/supervisord.conf /etc/supervisord.conf

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
