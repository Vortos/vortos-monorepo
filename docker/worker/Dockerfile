FROM php:8.4-fpm-alpine

RUN apk add --no-cache supervisor git unzip linux-headers $PHPIZE_DEPS postgresql-dev librdkafka-dev rabbitmq-c-dev

RUN pecl install redis mongodb rdkafka amqp \
    && docker-php-ext-enable redis mongodb rdkafka amqp \
    && docker-php-ext-install pdo pdo_pgsql pcntl

COPY docker/worker/supervisord.conf /etc/supervisord.conf

WORKDIR /var/www/html

# COPY . . for production

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]