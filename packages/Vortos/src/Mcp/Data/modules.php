<?php

declare(strict_types=1);

return [
    'domain' => [
        'description' => 'Base classes for DDD/CQRS building blocks. No DI extension — pure PHP abstractions.',
        'provides'    => [
            'AggregateRoot'  => 'Base class for all aggregates. recordEvent(object $payload) wraps the POPO in an EventEnvelope and registers it with the DomainEventLedger for automatic dispatch by the bus. pullDomainEvents() drains the aggregate-local buffer — testing/inspection only, never for dispatch.',
            'AggregateId'    => 'Base class for typed IDs. Wraps a UUID v7 string.',
            'EventEnvelope'  => 'Immutable wrapper produced by AggregateRoot::recordEvent(). Carries eventId, aggregateId, aggregateType, aggregateVersion, payloadType, schemaVersion, occurredAt, payload (POPO), and Metadata.',
            'Metadata'       => 'Value object inside EventEnvelope. Carries correlationId, causationId, traceId, tenantId, userId, and custom[].',
            'ValueObject'    => 'Base class for value objects. Implements equality by value.',
            'Command'        => 'Base DTO for CQRS commands.',
            'Query'          => 'Base DTO for CQRS queries.',
        ],
        'config'    => null,
        'commands'  => [],
    ],

    'cqrs' => [
        'description' => 'CommandBus and QueryBus with compile-time handler wiring, idempotency, and validation.',
        'provides'    => [
            'CommandBusInterface' => 'dispatch(Command): void — wraps handler in transaction, pulls domain events, dispatches to EventBus.',
            'QueryBusInterface'   => 'ask(Query): mixed — routes to the registered query handler.',
            '#[AsCommandHandler]' => 'Attribute to register a command handler. Class must be invokable.',
            '#[AsQueryHandler]'   => 'Attribute to register a query handler. Class must be invokable.',
            '#[AsProjectionHandler]' => 'Attribute to register a Kafka projection handler.',
            '#[AsIdempotencyKey]'    => 'Attribute on a command property to use as the idempotency key.',
            'idempotencyKey()'       => 'Override on a Command to return a custom idempotency key string.',
        ],
        'config' => [
            'idempotency_store' => 'redis (default) | in-memory — where deduplication keys are stored',
            'idempotency_ttl'   => 'seconds to retain deduplication keys (default: 86400)',
            'strict_mode'       => 'bool — throw if no handler found (default: true)',
        ],
        'commands' => [],
    ],

    'messaging' => [
        'description' => 'Event-driven messaging: Kafka producer/consumer, transactional outbox, dead letter queue, hooks, middleware.',
        'provides'    => [
            'EventBusInterface'      => 'dispatch(EventEnvelope): void — writes to outbox (default) or produces directly. dispatchBatch() for multiple envelopes.',
            '#[RegisterProducer]'    => 'Attribute to register a Kafka producer definition on a MessagingConfig class. publishes(Event::class) declares wire contracts (logical name derived by convention: {module}.{snake_case_class}, v1); publish(Event::class, as: \'x.y\', version: N) pins a name or bumps the schema version.',
            '#[RegisterConsumer]'    => 'Attribute to register a Kafka consumer definition on a MessagingConfig class. handles(\'wire.name\', LocalClass::class) maps a wire event to THIS module\'s contract class; upcast(\'wire.name\', from: 1, to: 2, upcaster: X::class) lifts old payload versions before hydration.',
            '#[AsEventHandler]'      => 'Attribute to register a Kafka event handler (consumer side). Handler method receives the POPO payload; optionally also EventEnvelope, Metadata, or header attributes. event: \'wire.name\' maps a foreign wire event to the handler\'s parameter class (the consuming module\'s OWN contract class — never import the producer\'s domain event).',
            'Wire contracts'         => 'payload_type on the wire is a logical name + version (registration.entry_approved.v1), NEVER a PHP class name. Consumers resolve names through a compiled closed-world map — a forged class name in a message is rejected, not instantiated. contracts.lock (vortos:contracts:lock/check) fails the build when a contract drifts without a version bump.',
            'Env references'         => 'Env::string()/Env::int()/Env::bool()/Env::float() (Vortos\\Foundation\\Config\\Env) — typed env refs for definition values (dsn, partitions, replicationFactor, SASL credentials). Resolved by the container at runtime. NEVER read $_ENV in a MessagingConfig: it runs at compile time.',
            'Header injection'       => '#[MessageId], #[CorrelationId], #[CausationId], #[TraceId], #[Timestamp], #[TenantId], #[UserId], #[Header("name")] — inject specific envelope fields as handler parameters.',
            'OutboxRelayWorker'      => 'Polls vortos_outbox and produces pending messages to Kafka.',
            'DeadLetterWriter'       => 'Writes permanently failed consumer messages to vortos_failed_messages.',
            'Hooks'                  => '#[BeforeDispatch], #[AfterDispatch], #[PreSend], #[BeforeConsume], #[AfterConsume] — consumer hooks receive EventEnvelope + consumerName. #[BeforeHandler], #[AfterHandler] — per-handler hooks fired directly by ConsumerRunner (not middleware stack); AfterHandler also receives handlerId, skipped bool, latencyMs, and optional Throwable.',
        ],
        'config' => [
            'driver'              => 'kafka (default) | in-memory',
            'dsn'                 => 'Kafka broker DSN e.g. kafka://kafka:9092',
            'outbox_table'        => 'vortos_outbox (default)',
            'outbox_max_attempts' => '5 (default) — retries before marking status=failed',
            'outbox_backoff_base' => '30 (default) — initial backoff in seconds, doubles each attempt',
            'outbox_backoff_cap'  => '3600 (default) — max backoff in seconds',
            'dlq_table'           => 'vortos_failed_messages (default)',
        ],
        'commands' => [
            'vortos:consume'            => 'Start the Kafka consumer worker process',
            'vortos:outbox:relay'       => 'Start the outbox relay worker (polls and produces to Kafka)',
            'vortos:outbox:replay'      => 'Reset permanently failed outbox rows back to pending. Flags: --latest --limit --transport --event-class --id --created-from --created-to --dry-run',
            'vortos:dlq:replay'         => 'Replay dead-lettered consumer messages back to Kafka. Flags: --latest --limit --transport --event-class --id --failed-from --failed-to --dry-run',
            'vortos:consumers:list'     => 'List all registered consumers with their transports and handler counts',
            'vortos:transports:list'    => 'List all registered transports with driver and topic details',
            'vortos:kafka:tail'         => 'Dev tool — stream raw Vortos events from a Kafka transport to the terminal. Payload is sanitized via PayloadSanitizerInterface. Args: <transport>. Flags: --brokers --group-id --from-beginning --limit. Verbose (-v) shows partition, offset, timestamp, all headers.',
            'vortos:consumer:tail'      => 'Dev tool (non-prod only) — observe a running consumer worker in real time via Redis pub/sub. Sets vortos:tail-ctrl:{consumer} Redis key; ConsumerTailControlHook on the worker polls for it and publishes per-handler events to vortos:tail:{consumer} channel. Ctrl+C deletes the key to deactivate. Shows per-handler outcomes (Succeeded/SucceededAfterRetries/SkippedIdempotent/DeadLettered/etc) with latency. Requires Redis cache driver. Args: <consumer>.',
            'vortos:consume --tail'     => 'Dev option on vortos:consume — activates ConsoleTailChannel for live per-handler output in the same process. Worker still runs normally; no Redis required.',
            'vortos:contracts:lock'     => 'Snapshot all published wire contracts (name, version, ctor schema) into contracts.lock. Run after INTENTIONAL contract changes; commit the file.',
            'vortos:contracts:check'    => 'Diff live wire contracts against contracts.lock; non-zero exit on drift. Run in CI. The same check runs at container compile (skip with VORTOS_CONTRACTS_SKIP_CHECK=1 for intentional re-locks).',
        ],
    ],

    'persistence' => [
        'description' => 'Abstractions for write (PostgreSQL/DBAL) and read (MongoDB) stores.',
        'provides'    => [
            'UnitOfWorkInterface'          => 'run(callable): void — wraps the callable in a DB transaction. CommandBus calls this automatically.',
            'WriteRepositoryInterface'     => 'save(AggregateRoot): void, delete(AggregateRoot): void',
            'ReadRepositoryInterface'      => 'findById(string): ?array, findBy(array): array',
        ],
        'config' => [
            'write_driver' => 'postgres (default)',
            'write_dsn'    => 'pgsql://user:pass@host:5432/dbname',
            'read_driver'  => 'mongo | none',
            'read_dsn'     => 'mongodb://user:pass@host:27017',
            'read_db_name' => 'database name for MongoDB',
        ],
        'commands' => [
            'vortos:migrate'            => 'Run pending migrations',
            'vortos:migrate:publish'    => 'Convert module SQL stubs to Doctrine migration classes',
            'vortos:migrate:status'     => 'Show migration state and unpublished stub warnings',
            'vortos:migrate:make'       => 'Generate an empty migration class',
            'vortos:migrate:rollback'   => 'Undo the last N migrations',
            'vortos:migrate:fresh'      => 'Drop all tables and rerun (dev/test only)',
            'vortos:migrate:adopt'      => 'Mark existing schema as executed without running SQL. Flags: --all-compatible --module-only --allow-unverified --verify --dry-run --force --json',
            'vortos:migrate:unadopt'    => 'Remove a migration tracking record without touching the schema. Omit version to unadopt the latest. Flags: --force',
            'vortos:migrate:verify'     => 'CI check: verify all executed framework migrations match the live database schema. Exit 0 = clean, Exit 1 = drift. Flags: --json',
        ],
    ],

    'cache' => [
        'description' => 'PSR-16 CacheInterface + TaggedCacheInterface. Drivers: Redis (prod), InMemory (dev/test).',
        'provides'    => [
            'CacheInterface'       => 'PSR-16 get/set/delete/clear — backed by active driver',
            'TaggedCacheInterface' => 'set(key, value, tags): void, invalidateTag(tag): void',
        ],
        'config' => [
            'driver'     => 'redis (default) | in-memory | array',
            'dsn'        => 'redis://host:6379',
            'prefix'     => 'string prefix for all keys — use env + app name e.g. dev_myapp_',
            'default_ttl'=> 'seconds (default: 3600)',
        ],
        'commands' => [
            'vortos:cache:clear'   => 'Clear all or tagged subset of cache. Flags: --tag=tagname',
            'vortos:cache:warmup'  => 'Run all registered CacheWarmerInterface implementations',
        ],
    ],

    'auth' => [
        'description' => 'JWT authentication, password hashing, token storage, rate limiting, API key M2M auth.',
        'provides'    => [
            'AuthMiddleware'         => 'Validates JWT bearer token on every request. Sets CurrentUser in RequestContext.',
            'CurrentUserProvider'    => 'getCurrentUser(): CurrentUser — identity of the authenticated caller.',
            'JwtService'             => 'issue(userId, claims): string, verify(token): Claims',
            'ArgonPasswordHasher'    => 'hash(password): string, verify(password, hash): bool',
            'ApiKeyAuthMiddleware'   => 'Validates X-API-Key header for M2M service calls.',
        ],
        'config' => [
            'algorithm'       => 'HS256 (default) | RS256. HS256 uses a shared secret; RS256 uses a private/public key pair.',
            'jwt_secret'      => 'HS256 only. Required. 64+ char hex secret (bin2hex(random_bytes(32))). Generated by vortos:setup.',
            'private_key_path'=> 'RS256 only. Path to PEM private key file (e.g. /run/secrets/jwt_private.pem).',
            'public_key_path' => 'RS256 only. Path to PEM public key file.',
            'jwt_ttl'         => 'Access token TTL in seconds (default: 900)',
            'refresh_ttl'     => 'Refresh token TTL in seconds (default: 604800)',
            'issuer'          => 'JWT iss claim (default: app name)',
            'token_storage'   => 'redis (default) | in-memory',
        ],
        'commands' => [],
    ],

    'authorization' => [
        'description' => 'Policy engine, role hierarchy, scoped/temporal permissions, RBAC, ownership checks.',
        'provides'    => [
            'PolicyEngine'              => 'can(userId, permission, resource?): bool',
            '#[AsPolicy]'               => 'Attribute to register a Policy class for a resource.',
            'AuthorizationMiddleware'   => 'Runs policy check for every request. Returns 403 on deny.',
            'OwnershipMiddleware'       => 'Checks resource ownership before policy check.',
        ],
        'config' => [
            'cache_permissions' => 'bool — cache policy decisions in Redis (default: true)',
            'cache_ttl'         => 'seconds (default: 300)',
        ],
        'commands' => [
            'vortos:auth:user-role:assign'     => 'Assign a runtime role to a user',
            'vortos:auth:user-role:remove'     => 'Remove a runtime role from a user',
            'vortos:auth:permissions'          => 'List all registered permissions',
            'vortos:auth:roles'                => 'Show a user\'s runtime roles',
            'vortos:auth:can'                  => 'Check a specific permission for a user',
            'vortos:auth:role-permission:grant'  => 'Grant a permission to a role',
            'vortos:auth:role-permission:revoke' => 'Revoke a permission from a role',
            'vortos:auth:seed'                 => 'Seed default authorization grants',
            'vortos:auth:explain'              => 'Explain an authorization decision (debug)',
        ],
    ],

    'http' => [
        'description' => 'HTTP routing, controllers, request/response pipeline, event subscribers.',
        'provides'    => [
            '#[AsRoute]'          => 'Attribute on a Controller method to register an HTTP route.',
            'JsonResponse'        => 'Framework response wrapper with automatic serialization.',
            'RequestContext'      => 'Per-request bag: currentUser, correlationId, tracing context.',
            'ErrorHandler'        => 'Maps exceptions to HTTP status codes and JSON error bodies.',
        ],
        'config'   => null,
        'commands' => [
            'vortos:debug:routes'    => 'List all registered routes with their HTTP methods and controllers',
            'vortos:debug:container' => 'List all registered DI container services',
        ],
    ],

    'security' => [
        'description' => 'HTTP security headers, CORS, CSRF, IP filter, request signing, encryption, secrets, data masking.',
        'provides'    => [
            'SecurityHeadersMiddleware'   => 'Adds HSTS, CSP, X-Frame-Options, X-Content-Type-Options headers.',
            'CorsMiddleware'              => 'Handles CORS preflight and adds Access-Control headers.',
            'CsrfMiddleware'              => 'CSRF token validation for state-changing web routes.',
            'IpFilterMiddleware'          => 'IP allowlist/denylist.',
            'RequestSignatureMiddleware'  => 'HMAC signature verification for webhooks.',
            'EncryptionService'           => 'AES-256-GCM encryption/decryption.',
            'SecretsProvider'             => 'EnvSecretsProvider | VaultSecretsProvider | AwsSsmSecretsProvider',
            'DataMaskingProcessor'        => 'Strips PII (email, phone, password fields) from log entries.',
            'PasswordPolicyService'       => 'Enforces min/max length, complexity rules, breach detection (HaveIBeenPwned).',
        ],
        'config' => [
            'headers_enabled'    => 'bool (default: true)',
            'cors_origins'       => 'array of allowed origins',
            'csrf_enabled'       => 'bool (default: true)',
            'ip_filter_mode'     => 'allowlist | denylist | off',
            'ip_filter_list'     => 'array of IPs/CIDRs',
            'secrets_provider'   => 'env | vault | aws-ssm',
            'masking_fields'     => 'array of field names to redact in logs',
        ],
        'commands' => [],
    ],

    'make' => [
        'description' => 'Code generator — 22 vortos:make:* commands scaffold DDD/CQRS artifacts from stubs.',
        'provides'    => ['GeneratorEngine — reads stubs from module Resources/stubs/ or app root stubs/ (user overrides)'],
        'config'      => null,
        'commands'    => [
            'vortos:make:context'             => 'Scaffold a bounded context directory tree (Domain/Application/Infrastructure/Presentation)',
            'vortos:make:aggregate'           => 'Generate an aggregate root, its typed AggregateId, and repository interface inside Domain/{Aggregate}/',
            'vortos:make:entity'              => 'Generate a child entity and its typed EntityId inside Domain/{Aggregate}/Entities/ (--aggregate required)',
            'vortos:make:value-object'        => 'Generate a ValueObject — --aggregate places it in Domain/{Aggregate}/ValueObjects/, --shared places it in Domain/Shared/ValueObjects/',
            'vortos:make:domain-event'        => 'Generate a pure POPO event class (final readonly, no base class) inside Domain/{Aggregate}/Event/',
            'vortos:make:domain-error'        => 'Generate a domain error with HTTP status mapping inside Domain/{Aggregate}/Error/',
            'vortos:make:command'             => 'Generate a Command DTO + Handler skeleton',
            'vortos:make:query'               => 'Generate a Query DTO + Handler skeleton',
            'vortos:make:projection-handler'  => 'Generate a Kafka projection handler (read model updater)',
            'vortos:make:consumer'            => 'Generate a Kafka event handler (consumer side)',
            'vortos:make:messaging-config'    => 'Generate a MessagingConfig class with RegisterProducer/RegisterConsumer',
            'vortos:make:middleware'          => 'Generate a Kafka consumer middleware class',
            'vortos:make:hook'                => 'Generate a messaging lifecycle hook',
            'vortos:make:controller'          => 'Generate an HTTP controller + request DTO',
            'vortos:make:write-repository'    => 'Generate a PostgreSQL write repository (DBAL)',
            'vortos:make:read-repository'     => 'Generate a MongoDB read repository',
            'vortos:make:authorization-policy'=> 'Generate a resource authorization policy class',
            'vortos:make:ownership-policy'    => 'Generate an ownership policy class',
            'vortos:make:feature-policy'      => 'Generate a feature access policy class',
            'vortos:make:rate-limit-policy'   => 'Generate a rate limit policy class',
            'vortos:make:quota-policy'        => 'Generate a quota policy class',
            'vortos:make:quota-resolver'      => 'Generate a quota subject resolver class',
            'vortos:make:session-policy'      => 'Generate a session limit policy class',
        ],
    ],

    'logger' => [
        'description' => 'PSR-3/Monolog logging with named channels, structured records, redaction, request context, trace correlation, and alert integrations.',
        'provides' => [
            'LoggerInterface (PSR-3)' => 'Aliased to the app channel. Inject LoggerInterface for general logging.',
            'Named channels'          => 'app, http, cqrs, messaging, cache, security, query — inject with #[Target("channelName")]',
            'CorrelationIdProcessor'  => 'Adds correlation_id and trace_id to log records when tracing is active.',
            'RedactionProcessor'      => 'Redacts common secrets and PII keys from context and extra fields.',
            'StructuredLogProcessor'  => 'Adds ECS/OpenTelemetry-style service, environment, dataset, and logger fields.',
            'RequestContextProcessor' => 'Adds bounded HTTP, tenant, and user context without query strings or request bodies.',
            'Buffer flush listener'   => 'Flushes buffered records on HTTP terminate, console terminate, and console error.',
            'Alerting'                => 'Sentry fail-fast integration plus Slack and Email emergency handlers.',
        ],
        'config' => [
            'level'                       => 'debug | info | warning | error per channel',
            'format'                      => 'json (default) | line',
            'redaction'                   => 'enabled by default; add project-specific sensitive keys',
            'structured'                  => 'enabled by default; adds service.name/version/environment and logger metadata',
            'request_context'             => 'enabled by default; bounded safe request/user/tenant fields',
            'introspection'               => 'enabled in dev only, disabled outside dev for security and performance',
            'fail_on_missing_integrations'=> 'true by default; Sentry config fails if sentry/sentry is absent',
            'sentry_dsn'                  => 'optional — routes ERROR+ to Sentry',
            'slack_webhook'               => 'optional emergency sink — routes CRITICAL to Slack synchronously',
        ],
        'commands' => [],
    ],

    'tracing' => [
        'description' => 'NoOp-by-default tracing abstraction with OpenTelemetry OTLP export, parent-based trace-level sampling, propagation, and safe baggage.',
        'provides'    => [
            'TracingInterface'      => 'startSpan(name, attributes), injectHeaders(headers), extractContext(headers), baggage methods, currentCorrelationId()',
            'SpanInterface'         => 'addAttribute(key, value), recordException(Throwable), end()',
            'NoOpTracer'            => 'Default adapter with no exporter work.',
            'OpenTelemetryTracer'   => 'OTLP exporter adapter with W3C trace context propagation.',
            'SamplingTracer'        => 'Parent-based trace-level sampling: RatioSampler, AlwaysOnSampler, AlwaysOffSampler.',
            'Controller attributes' => '#[TraceWith] and #[DisableTracing] for HTTP span control.',
            'Baggage'               => 'Small non-sensitive propagation values such as tenant.id.',
        ],
        'config' => [
            'adapter'          => 'noop (default) | opentelemetry',
            'service'          => 'service.name, service.version, deployment.environment',
            'otlp'             => 'endpoint, headers, timeoutMs for OpenTelemetry exporter',
            'sampler'          => 'ratio (default) | always-on | always-off',
            'ratio'            => 'float 0.0–1.0 (default: 0.1)',
            'disabled_modules' => 'array of module names to exclude from tracing',
            'trust_remote'     => 'bool — trust W3C traceparent header from upstream (default: false)',
        ],
        'commands' => [],
    ],

    'observability' => [
        'description' => 'Publishable observability templates for Prometheus, Grafana, Alertmanager, Datadog, and New Relic. No runtime exporters or network calls.',
        'provides' => [
            'Template registry' => 'Lists available stacks and files.',
            'Template publisher' => 'Copies starter assets into the application observability/ directory.',
            'Prometheus assets' => 'Recording rules and alert rules for Vortos metrics.',
            'Grafana assets' => 'Dashboard JSON for HTTP, CQRS, messaging, cache, persistence, and security metrics.',
            'Alertmanager assets' => 'Routing and receiver example for Vortos labels.',
            'Datadog assets' => 'Dashboard and monitor examples for StatsD metrics, JSON logs, and OTLP traces.',
            'New Relic assets' => 'Dashboard and alert examples for metrics, logs, and OTLP traces.',
        ],
        'config' => null,
        'commands' => [
            'vortos:observability:list' => 'List available observability template stacks',
            'vortos:observability:publish' => 'Publish templates with --stack, --force, and --dry-run',
        ],
    ],

    'foundation' => [
        'description' => 'Health checks, pre-flight diagnostics, boot error rendering, and worker mode service resetter.',
        'provides'    => [
            'HealthRegistry'         => 'Register HealthCheckInterface implementations. Exposed via /health/ready and /health/live.',
            'ServicesResetter'       => 'Calls reset() on all ResettableInterface services after each request in worker mode.',
            'DoctorRegistry'         => 'Runs all registered DoctorCheckInterface implementations. Tag with #[AsDoctor].',
            '#[AsDoctor]'            => 'Attribute to register a DoctorCheckInterface as a pre-flight diagnostic check.',
            'DoctorCheckInterface'   => 'run(): DoctorResult — implement to add a custom doctor check.',
            'BootErrorRenderer'      => 'Formats boot exceptions in CLI with human-readable hints for common failures.',
        ],
        'config'   => null,
        'commands' => [
            'vortos:health'  => 'Check all registered HealthCheckInterface services. Flags: --json (machine-readable output)',
            'vortos:doctor'  => 'Run all registered pre-flight diagnostic checks. Flags: --fail-on-warning (exit 1 on warnings)',
        ],
    ],

    'setup' => [
        'description' => 'Project bootstrap wizard — writes .env, Docker files, and config stubs.',
        'provides'    => ['Interactive setup: preset selection, env generation, Docker file publishing, config publishing'],
        'config'      => null,
        'commands'    => [
            'vortos:setup'           => 'Interactive project setup wizard. Flags: --preset, --profile, --dry-run, --force',
            'vortos:config:publish'  => 'Publish module config stubs to project config/. Flags: --module=name, --force, --dry-run',
        ],
    ],

    'feature_flags' => [
        'description' => 'Runtime feature flags with per-user, attribute, and percentage-rollout targeting rules.',
        'provides'    => [
            'FlagRegistry'              => 'isEnabled(name): bool, getVariant(name): ?string — evaluate flags against the current request context.',
            'FlagEvaluator'             => 'Evaluates flag rules against a FlagContext in priority order: users → attribute → percentage.',
            '#[FeatureFlag("name")]'    => 'Attribute on a Controller or handler method to gate access behind a flag. Returns 403 when the flag is off.',
            'FeatureFlagMiddleware'     => 'HTTP middleware that evaluates #[FeatureFlag] attributes per request.',
            'FlagStorageInterface'      => 'findAll(): FeatureFlag[], findByName(name): ?FeatureFlag, save(flag): void, delete(name): void',
        ],
        'config' => [
            'storage'    => 'database (default) — stores flags in vortos_feature_flags table',
            'cache_ttl'  => 'seconds to cache flag state in Redis (default: 60). Set 0 to disable caching.',
        ],
        'commands' => [
            'vortos:flags:list'     => 'List all feature flags with status, rule count, and description. Flags: --json',
            'vortos:flags:show'     => 'Show full details of a feature flag including all targeting rules. Args: <name>. Flags: --json',
            'vortos:flags:create'   => 'Create a new feature flag. Args: <name>. Options: --description, --enable',
            'vortos:flags:enable'   => 'Enable a feature flag globally. Args: <name>.',
            'vortos:flags:disable'  => 'Disable a feature flag (kill switch — off for everyone instantly). Args: <name>.',
            'vortos:flags:delete'   => 'Permanently delete a feature flag. Args: <name>.',
            'vortos:flags:add-rule' => 'Add a targeting rule to a flag. Args: <name>. Options: --type=users|attribute|percentage, --users, --attribute, --operator, --value, --percentage, --clear',
        ],
    ],

    'mcp' => [
        'description' => 'MCP server for AI-assisted Vortos development. Exposes framework and project knowledge as MCP tools.',
        'provides'    => ['vortos:mcp:serve (stdio MCP server)', 'vortos:mcp:install (client config writer)', 'vortos:mcp:doctor (status check)'],
        'config'      => null,
        'commands'    => [
            'vortos:mcp:serve'   => 'Start the MCP server (stdio — AI clients auto-start this). Flags: --http, --port',
            'vortos:mcp:install' => 'Write MCP config to AI client settings. Flags: --client=auto|codex|claude|cursor|windsurf|all, --global',
            'vortos:mcp:doctor'  => 'Show MCP server status, detected clients, and available tools',
        ],
    ],
];
