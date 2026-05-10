<?php

declare(strict_types=1);

use Vortos\Cqrs\Command\Idempotency\InMemoryCommandIdempotencyStore;
use Vortos\Cqrs\Command\Idempotency\RedisCommandIdempotencyStore;
use Vortos\Cqrs\DependencyInjection\VortosCqrsConfig;

// This file configures the CQRS command bus behaviour.
// Infrastructure (write DB driver) is chosen via VORTOS_WRITE_DB_DRIVER in .env.
//
// For per-environment overrides create config/{env}/cqrs.php.

return static function (VortosCqrsConfig $config): void {
    $config
        ->commandBus()

        // Idempotency store — prevents duplicate command execution.
        //
        // RedisCommandIdempotencyStore  — persistent across requests (recommended for prod).
        //                                 Requires Redis and vortos/vortos-cache.
        // InMemoryCommandIdempotencyStore — process-local, lost on restart (dev/testing only).
        ->idempotencyStore(RedisCommandIdempotencyStore::class)

        // How long a command ID is remembered, in seconds.
        // Duplicate commands arriving within this window are rejected.
        // 86400 = 24 hours.
        ->idempotencyTtl(86400)

        // Strict mode: throw DuplicateCommandException on duplicates.
        // Lenient mode (default): silently drop duplicate commands.
        //
        // Use strict mode when the caller must know a command was already processed,
        // e.g. to surface a 409 Conflict to the API consumer.
        ->strictIdempotency(false)
    ;
};
