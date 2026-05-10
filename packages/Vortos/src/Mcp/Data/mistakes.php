<?php

declare(strict_types=1);

return [
    [
        'wrong'  => 'new Connection() or new \Doctrine\DBAL\Connection(...)',
        'right'  => 'ConnectionFactory::fromDsn($dsn) — or inject Connection from the DI container',
        'why'    => 'A second Connection instance creates a second transaction. The CommandBus transaction and the new connection are independent — your domain write and outbox write are no longer atomic.',
    ],
    [
        'wrong'  => 'insert() in a projection handler',
        'right'  => 'upsert() always in projection handlers',
        'why'    => 'Kafka at-least-once delivery replays events on restart. insert() throws a duplicate key error on replay. upsert() is idempotent.',
    ],
    [
        'wrong'  => 'void return from a command handler',
        'right'  => 'return $aggregate from the command handler',
        'why'    => 'The CommandBus calls pullDomainEvents() on the handler\'s return value. A void return silently drops all domain events — EventBus never sees them, outbox is never written.',
    ],
    [
        'wrong'  => 'Calling $unitOfWork->run() inside a handler',
        'right'  => 'Never call run() inside a handler — the CommandBus wraps the handler in TransactionalMiddleware automatically',
        'why'    => 'Nested transactions cause double-commit or nested BEGIN errors depending on the driver. The bus owns the transaction boundary.',
    ],
    [
        'wrong'  => 'beginTransaction() / commit() / rollBack() inside a handler or service',
        'right'  => 'Let the CommandBus manage the transaction. Only call these in bootstrap code or CLI scripts that run outside the bus.',
        'why'    => 'Same as above — the bus is the transaction owner.',
    ],
    [
        'wrong'  => 'ObjectId or BSONDocument as MongoDB _id',
        'right'  => 'String UUID v7 always. Store _id as a plain PHP string.',
        'why'    => 'Vortos IDs are UUID v7 strings. ObjectId makes cross-store correlation impossible and breaks serialization assumptions.',
    ],
    [
        'wrong'  => '$redis->flushDb() or $redis->flushAll()',
        'right'  => '$cache->clear() — uses SCAN with the app prefix and deletes matching keys',
        'why'    => 'FLUSHDB wipes the entire Redis database, including Kafka consumer group offsets. This causes all consumers to replay from the beginning of their topics.',
    ],
    [
        'wrong'  => 'Both #[AsIdempotencyKey] on a property AND overriding idempotencyKey() on the same Command',
        'right'  => 'Use one approach — either the attribute or the override, never both',
        'why'    => 'The compile-time idempotency resolver will detect the conflict and throw a DI configuration error.',
    ],
    [
        'wrong'  => 'Two Policy classes for the same resource/aggregate',
        'right'  => 'One Policy per resource — combine all permission checks into that single class',
        'why'    => 'The AuthorizationPackage compiler pass asserts one policy per resource. Two policies for the same resource throw a compile-time error.',
    ],
    [
        'wrong'  => 'Injecting EntityManager or using Doctrine ORM for the write side',
        'right'  => 'Use Doctrine DBAL directly via the injected Connection for write repositories',
        'why'    => 'Vortos write repositories are DBAL-based. ORM introduces identity map caching that conflicts with the explicit UnitOfWork pattern.',
    ],
    [
        'wrong'  => '$eventBus->dispatch() from a controller without an active transaction',
        'right'  => 'Dispatch events from inside a command handler (which is wrapped in a transaction) — never from a controller directly',
        'why'    => 'OutboxWriter uses the caller\'s active DB connection. Without a transaction, the outbox write succeeds independently of your domain write — the outbox pattern is defeated.',
    ],
    [
        'wrong'  => 'Registering CachePackage after MessagingPackage or CqrsPackage',
        'right'  => 'CachePackage must be registered before MessagingPackage and CqrsPackage in Container.php',
        'why'    => 'Both packages resolve CacheInterface during their extension load phase. If CachePackage has not loaded yet, the alias is undefined and the container throws.',
    ],
    [
        'wrong'  => 'Not calling cleanUp() / ServicesResetter::reset() between requests in worker mode',
        'right'  => 'Register a request lifecycle listener that calls cleanUp() after every response in FrankenPHP worker mode',
        'why'    => 'Per-request state (CurrentUser, UnitOfWork, correlation ID) bleeds into the next request. This causes identity leaks — user A sees user B\'s data.',
    ],
    [
        'wrong'  => 'Putting secrets in .env for production (JWT_SECRET, DB passwords)',
        'right'  => 'Use VaultSecretsProvider or AwsSsmSecretsProvider for production secrets',
        'why'    => '.env is committed to version control and accessible in plaintext on the server filesystem. Secrets must be fetched at runtime from a secure store.',
    ],
    [
        'wrong'  => 'Domain logic in a controller',
        'right'  => 'Controllers are thin: validate input, dispatch a Command or Query via the bus, return a response. All business logic lives in the Domain or Application layer.',
        'why'    => 'Domain logic in controllers cannot be tested without an HTTP stack, cannot be reused from CLI commands, and violates layer separation.',
    ],
];
