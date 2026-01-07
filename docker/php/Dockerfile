FROM php:8.4-fpm-alpine

WORKDIR /var/www/html 

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache git unzip linux-headers $PHPIZE_DEPS postgresql-dev librdkafka-dev rabbitmq-c-dev

RUN pecl install redis mongodb rdkafka amqp && docker-php-ext-enable redis mongodb rdkafka amqp

RUN docker-php-ext-install pdo pdo_pgsql pcntl