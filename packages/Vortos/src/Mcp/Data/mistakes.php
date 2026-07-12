<?php

declare(strict_types=1);

return [
    [
        'wrong'  => 'In a DI extension/compiler pass: ->setArgument(\'$tiers\', [new EscalationTier(0, 0)]) or $keyring = $config->buildKeyring(); ->setArguments([$keyring, ...]) — passing an instantiated domain object as a service argument',
        'right'  => 'Pass an inline Definition instead: ->setArgument(\'$tiers\', [new Definition(EscalationTier::class, [0, 0])]); register the object as its own service (a factory Definition) and pass new Reference(...). Scalars, Reference, enums and Argument\\* value objects are also fine.',
        'why'    => 'Prod+HTTP caches the container via Symfony PhpDumper, which cannot serialize a raw object instance as a service argument ("Unable to dump a service container if a parameter is an object..."). It only fails on a real prod HTTP boot, one offender at a time. The always-on ContainerDumpabilityPass (Foundation) now fails the compile listing every offender at once — heed it rather than working around it.',
    ],
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
        'wrong'  => 'Using #[DefaultImpl] on a class that is meant to replace a framework-provided binding, then being confused when the framework\'s original binding is still used',
        'right'  => 'Use #[OverrideImpl] instead — it unconditionally replaces any existing alias or definition, including those registered by framework extensions',
        'why'    => 'Framework extensions register their bindings during load(), before DefaultImplCompilerPass runs. DefaultImplCompilerPass intentionally skips an interface that already has an alias or definition — it is designed to yield to explicit registrations. OverrideImplCompilerPass runs after and always wins, regardless of what was registered before.',
    ],
    [
        'wrong'  => '#[AsDecorator] with no constructor parameter typed to the decorated target, or with a parameter typed to a different interface',
        'right'  => 'Add a constructor parameter whose declared type matches $decorates exactly — the name does not matter, only the type. DecoratorCompilerPass finds the param by scanning ReflectionParameter types, not by name.',
        'why'    => 'DecoratorCompilerPass performs an exact type-name match between the $decorates string and each constructor parameter\'s ReflectionNamedType::getName(). If no match is found the pass throws a compile-time error listing the missing parameter. A param named $inner typed to a parent interface or a union type will not match.',
    ],
    [
        'wrong'  => 'Using #[AsDecorator] when the intent is to replace the original service entirely',
        'right'  => 'Use #[OverrideImpl] to replace; use #[AsDecorator] only when the original implementation must stay alive and be called via $inner',
        'why'    => '#[AsDecorator] keeps the original service in the container under a .vortos_inner_N alias and injects it into the decorator. The original class is still instantiated and its constructor dependencies are resolved. If you want a clean replacement with no instance of the old class, #[OverrideImpl] is correct.',
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
    [
        'wrong'  => 'Adding an app-side compiler pass to register deploy commands, bind a PSR-18 client, alias SecretsProviderInterface, or set %vortos.deploy.*% params — compensating for the framework in your app',
        'right'  => 'None of that glue is needed anymore. deploy/deploy:doctor/deploy:rollback register themselves; a Guzzle PSR-18/17 default is bound if you have not; SecretsProviderInterface defaults to EnvSecretsProvider; the deploy endpoint params are set by the extension. Just `composer update` the framework.',
        'why'    => 'These were the P0 boot/wiring defects (cross-package has()-in-load(), unbound ports, missing params/aliases) — all fixed in the framework. App-side passes duplicating that work are now dead weight; delete them.',
    ],
    [
        'wrong'  => 'Declaring alert rules / required secrets / pipeline settings by redefining the vendor service (AlertRuleSet, RequiredSecrets, PipelineDefinition)',
        'right'  => 'Use the config file surface: config/alerts.php (list<AlertRule>), config/secrets.php (list<SecretReference>), config/deploy.php (Closure(DeploymentDefinitionBuilder)), config/pipeline.php (array of overrides). Each is loaded by a factory.',
        'why'    => 'The config/*.php surfaces are the supported, override-free way to configure these packages, consistent with cache/tracing/logging. Overriding the service definition is fragile and unnecessary.',
    ],
    [
        'wrong'  => 'Writing an alter migration as a schema provider that calls $schema->getTable(\'existing_table\') unconditionally',
        'right'  => 'Guard with if ($schema->hasTable($this->t(\'existing_table\'))) { ... } — vortos:migrate:publish runs define() against a FRESH empty schema, so an unguarded getTable() throws (SchemaException) and, before the fix, aborted the whole publish run.',
        'why'    => 'Schema providers are re-introspected on an empty schema at publish time. Publish is now resilient (one bad stub no longer aborts the run) and SchemaProvidersArePublishSafeTest enforces the guard, but authoring an alter provider without hasTable() still produces an unpublishable migration.',
    ],
    [
        'wrong'  => 'Using `vortos backup:wal-archive %p` as Postgres archive_command when Postgres runs in its own (PHP-less) container',
        'right'  => 'Run `vortos:backup:pitr:recipe` — it emits a pure `cp` archive_command (to a shared volume) plus a wal-shipper worker in the app image that ships segments off-host. The DB image needs no PHP.',
        'why'    => 'archive_command executes inside the Postgres container; a stock postgres:alpine image has no Vortos CLI. The recipe decouples "archive to volume" (DB) from "ship off-host" (app worker).',
    ],
    [
        'wrong'  => 'Dispatching onto the bus (EventBusInterface) from outside a business transaction — e.g. an audit/telemetry recorder, an HTTP middleware after-hook, a read path, a scheduled/CLI job',
        'right'  => 'Inject StandaloneEventBusInterface (it opens its own DB transaction when none is active, and joins an existing one when there is). Same for object-store writes on a CLI/maintenance path: use ImmediateObjectStoreInterface, not the transactional ObjectStoreInterface.',
        'why'    => 'The transactional outbox write requires an active DB transaction ("Messaging transactional outbox write requires an active database transaction"). Outside a CommandBus tx the dispatch throws — and if the caller swallows it (fail-open), every event is silently dropped. vortos-audit\'s AsyncAuditRecorder hit exactly this.',
    ],
    [
        'wrong'  => 'Enabling audit_events RLS + the per-request app.current_tenant GUC, then serving an endpoint that returns the caller\'s OWN cross-scope rows (e.g. their platform-scoped auth events) without relaxing the GUC',
        'right'  => 'Clear the tenant GUC (SELECT set_config(\'app.current_tenant\', \'\', false)) for that specific own-data query; the actor-id / owner filter already confines results to the caller, so there is no cross-tenant leak.',
        'why'    => 'The "restrict only when the GUC is set" policy makes tenant_id = current_setting(...); platform rows have tenant_id IS NULL, so a tenant-scoped session sees NONE of them. An "all my activity" endpoint would silently drop the user\'s logins.',
    ],
];
