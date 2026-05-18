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

RUN mkdir -p /var/www/html/var/log /var/www/html/var/cache \
    && chmod -R 777 /var/www/html/var

RUN groupadd --gid 1000 app \
    && useradd --uid 1000 --gid app --shell /bin/bash --no-create-home app

# Pre-create Caddy's data and config dirs with correct ownership so named
# volumes are initialised as uid 1000 on first run (Docker copies image
# directory contents into an empty volume on first mount).
RUN mkdir -p /data/caddy /config/caddy \
    && chown -R app:app /data /config

# ============================================================
# Stage 3a — Backend: HTTP server target
# ============================================================
FROM base AS backend

# FrankenPHP built with XCADDY_SETCAP=1 holds cap_net_bind_service,
# so it can bind ports 80/443 without root.
USER app:app

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