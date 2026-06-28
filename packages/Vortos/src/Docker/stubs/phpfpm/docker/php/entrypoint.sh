#!/bin/sh
set -e

php bin/console cache:warmup

exec docker-php-entrypoint "$@"
