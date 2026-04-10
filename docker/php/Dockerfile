# ============================================================
# Stage 1 — Builder: compile custom FrankenPHP binary
# with caddy-ratelimit module via xcaddy
# ============================================================
FROM dunglas/frankenphp:builder AS builder

COPY --from=caddy:builder /usr/bin/xcaddy /usr/bin/xcaddy

RUN CGO_ENABLED=1 \
    XCADDY_SETCAP=1 \
    XCADDY_GO_BUILD_FLAGS="-ldflags='-w -s'" \
    CGO_CFLAGS=$(php-config --includes) \
    CGO_LDFLAGS="$(php-config --ldflags) $(php-config --libs)" \
    xcaddy build \
    --output /usr/local/bin/frankenphp \
    --with github.com/dunglas/frankenphp=./ \
    --with github.com/dunglas/frankenphp/caddy=./caddy/ \
    --with github.com/dunglas/caddy-cbrotli \
    --with github.com/mholt/caddy-ratelimit

# ============================================================
# Stage 2 — Base: shared PHP + extensions layer
# Both backend and worker build FROM this
# ============================================================
FROM dunglas/frankenphp:1-php8.5-trixie AS base

COPY --from=builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip \
    libpq-dev \
    librdkafka-dev \
    librabbitmq-dev \
    linux-headers-generic \
    && rm -rf /var/lib/apt/lists/*

RUN pecl install redis mongodb rdkafka amqp \
    && docker-php-ext-enable redis mongodb rdkafka amqp \
    && docker-php-ext-install pdo pdo_pgsql pcntl

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ============================================================
# Stage 3a — Backend: HTTP server target
# ============================================================
FROM base AS backend

EXPOSE 80 443

# ============================================================
# Stage 3b — Worker: supervisord target
# ============================================================
FROM base AS worker

RUN apt-get update && apt-get install -y --no-install-recommends supervisor \
    && rm -rf /var/lib/apt/lists/*

RUN mkdir -p /var/log/supervisor

COPY docker/worker/supervisord.conf /etc/supervisord.conf

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]