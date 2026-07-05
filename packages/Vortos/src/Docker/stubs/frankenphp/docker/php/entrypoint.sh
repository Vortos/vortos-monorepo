#!/bin/sh
set -e

php bin/console vortos:cache:warmup

exec docker-php-entrypoint "$@"
