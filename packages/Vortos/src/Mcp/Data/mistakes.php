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
        'wrong'  => 'foreach ($aggregate->pullDomainEvents() as $e) { $eventBus->dispatch($e); } inside a handler',
        'right'  => 'Just record events in the aggregate — the DomainEventLedger collects every recordEvent() and the CommandBus dispatches them after the handler returns, whatever the handler returns.',
        'why'    => 'The ledger already holds those events; dispatching them manually publishes each event twice. Return whatever the caller needs (void, a DTO, the aggregate) — the return value has no effect on event dispatch.',
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
        'wrong'  => 'class UserRegisteredEvent extends DomainEvent',
        'right'  => 'final readonly class UserRegistered { public function __construct(public string $email, ...) {} }',
        'why'    => 'Domain events must be pure POPOs — final, readonly, constructor-promoted properties, no methods other than __construct, no base class. The compiler pass enforces this (F1–F5). aggregateId and occurredAt live on EventEnvelope, not on the payload.',
    ],
    [
        'wrong'  => 'Reading $event->aggregateId() or $event->occurredAt() in a projection handler',
        'right'  => 'Declare EventEnvelope $envelope as a second parameter and read $envelope->aggregateId and $envelope->occurredAt',
        'why'    => 'Event payloads are pure data — they carry no identity or timing metadata. That context lives on the EventEnvelope wrapper injected by the ConsumerRunner.',
    ],
    [
        'wrong'  => 'Domain logic in a controller',
        'right'  => 'Controllers are thin: validate input, dispatch a Command or Query via the bus, return a response. All business logic lives in the Domain or Application layer.',
        'why'    => 'Domain logic in controllers cannot be tested without an HTTP stack, cannot be reused from CLI commands, and violates layer separation.',
    ],
    [
        'wrong'  => 'Assuming a deployment/ops package (vortos-deploy, vortos-secrets, vortos-backup, vortos-alerts, vortos-pipeline, vortos-release, vortos-iac, vortos-analytics, vortos-health, vortos-ops-kit) is available because the framework version was bumped',
        'right'  => 'Call list_project_modules (reads composer.lock) or get_module_docs first — composer update only updates packages already required; it never adds new ones. Most of these are opt-in split packages.',
        'why'    => 'These classes/commands/DI services simply do not exist in vendor/ unless explicitly composer-required, regardless of how new the framework is. Writing code against them when they are not installed produces a class-not-found error, not a graceful fallback.',
    ],
    [
        'wrong'  => 'Hand-writing a GitHub Actions workflow or Terraform .tf.json file when vortos-pipeline / vortos-iac is installed',
        'right'  => 'Change the PipelineDefinition / #[InfraConfig] declaration and regenerate with `pipeline:generate` / `vortos:iac:export`',
        'why'    => 'Both tools render deterministic, byte-identical output from the declared model — `pipeline:verify` / `--check` treat any divergence as drift and fail CI. A hand-edit is overwritten on the next regeneration anyway, or flagged as drift if someone forgets to regenerate.',
    ],
    [
        'wrong'  => 'Storing a SecretValue::reveal() result in a property, cache entry, or log context "just for convenience"',
        'right'  => 'Call reveal() only at the exact call site that needs the plaintext, pass it directly into that call, let it go out of scope immediately',
        'why'    => 'SecretValue is redacted-by-construction specifically so a plaintext never has more than one deliberate, auditable point of existence. Re-storing the revealed string defeats the entire protection — there is nothing left guarding it once it leaves reveal().',
    ],
    [
        'wrong'  => 'Calling a driver operation without checking capabilities() first, assuming every driver of a port behaves identically',
        'right'  => 'Check $driver->capabilities()->supports(SomeCapability::X) or rely on CapabilityValidator::assertSatisfies() before relying on optional behavior',
        'why'    => 'Ops Kit drivers honestly declare partial support — a driver that does not support a capability throws UnsupportedCapabilityException rather than silently no-opping. Code that assumes uniform behavior across drivers (e.g. "every Deploy target supports canary") will work with one driver and throw with another.',
    ],
    [
        'wrong'  => 'Treating a deploy:rollback / vortos:iac:apply refusal as a bug and looking for a flag to force past it',
        'right'  => 'Read the refusal message (RollbackRefusedException, PlanStaleException, DestructiveChangeRefusedException) — it names the exact schema/plan/blast-radius problem and the remediation',
        'why'    => 'These refusals are the fail-closed design working correctly — e.g. RollbackGuard found the target build\'s schema is not a safe subset of what\'s currently applied. Forcing past it (where a flag even exists) risks the exact corruption the guard exists to prevent.',
    ],
];
