<?php

declare(strict_types=1);

return [
    'domain' => [
        'description' => 'Base classes for DDD/CQRS building blocks. No DI extension — pure PHP abstractions.',
        'provides'    => [
            'AggregateRoot' => 'Base class for all aggregates. Holds domain events via recordEvent(). pullDomainEvents() drains and returns them.',
            'AggregateId'   => 'Base class for typed IDs. Wraps a UUID v7 string.',
            'DomainEvent'   => 'Base class for domain events. Carries eventId (UUID v7) and occurredAt.',
            'ValueObject'   => 'Base class for value objects. Implements equality by value.',
            'Command'       => 'Base DTO for CQRS commands.',
            'Query'         => 'Base DTO for CQRS queries.',
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
            'EventBusInterface'      => 'dispatch(DomainEvent): void — writes to outbox (default) or produces directly.',
            '#[RegisterProducer]'    => 'Attribute to register a Kafka producer definition on a MessagingConfig class.',
            '#[RegisterConsumer]'    => 'Attribute to register a Kafka consumer definition on a MessagingConfig class.',
            '#[AsEventHandler]'      => 'Attribute to register a Kafka event handler (consumer side).',
            'OutboxRelayWorker'      => 'Polls vortos_outbox and produces pending messages to Kafka.',
            'DeadLetterWriter'       => 'Writes permanently failed consumer messages to vortos_failed_messages.',
            'Hooks'                  => '#[BeforeDispatch], #[AfterDispatch], #[PreSend], #[BeforeConsume], #[AfterConsume]',
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
            'vortos:consume'          => 'Start the Kafka consumer worker process',
            'vortos:outbox:relay'     => 'Start the outbox relay worker (polls and produces to Kafka)',
            'vortos:outbox:replay'    => 'Reset permanently failed outbox rows back to pending. Flags: --latest --limit --transport --event-class --id --created-from --created-to --dry-run',
            'vortos:dlq:replay'       => 'Replay dead-lettered consumer messages back to Kafka. Flags: --latest --limit --transport --event-class --id --failed-from --failed-to --dry-run',
            'vortos:consumers:list'   => 'List all registered consumers',
            'vortos:transports:list'  => 'List all registered transports',
            'vortos:setup:messaging'  => 'Publish outbox and DLQ migration files',
        ],
    ],

    'persistence' => [
        'description' => 'Abstractions for write (PostgreSQL/DBAL) and read (MongoDB) stores.',
        'provides'    => [
            'UnitOfWorkInterface'          => 'run(callable): void — wraps the callable in a DB transaction. CommandBus calls this automatically.',
            'WriteRepositoryInterface'     => 'save(AggregateRoot): void, findById(AggregateId): ?AggregateRoot',
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
            'vortos:migrate:baseline'   => 'Mark all migrations as executed (legacy schema import)',
            'vortos:migrate:adopt'      => 'Mark verified existing schema as executed',
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
            'jwt_secret'      => 'Required. 32+ char secret from secrets provider.',
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
        'description' => 'Code generator — 16 vortos:make:* commands scaffold DDD/CQRS artifacts from stubs.',
        'provides'    => ['GeneratorEngine — reads stubs from module Resources/stubs/ or app root stubs/ (user overrides)'],
        'config'      => null,
        'commands'    => [
            'vortos:make:context'             => 'Scaffold a bounded context directory tree (Domain/Application/Infrastructure/Representation)',
            'vortos:make:entity'              => 'Generate Aggregate + AggregateId + repository interface',
            'vortos:make:value-object'        => 'Generate a ValueObject class',
            'vortos:make:domain-event'        => 'Generate a DomainEvent class',
            'vortos:make:domain-exception'    => 'Generate a domain exception class',
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
            'vortos:make:policy'              => 'Generate an authorization Policy class',
        ],
    ],

    'logger' => [
        'description' => 'Monolog with named channels, structured JSON output, and alerting integrations.',
        'provides' => [
            'LoggerInterface (PSR-3)' => 'Aliased to the app channel. Inject LoggerInterface for general logging.',
            'Named channels'          => 'app, http, cqrs, messaging, cache, security, query — inject with #[Target("channelName")]',
            'CorrelationIdProcessor'  => 'Adds correlation_id and trace_id to every log entry.',
            'Alerting'                => 'Sentry, Slack, Email handlers configurable per channel',
        ],
        'config' => [
            'level'           => 'debug | info | warning | error per channel',
            'format'          => 'json (default) | line',
            'sentry_dsn'      => 'optional — routes ERROR+ to Sentry',
            'slack_webhook'   => 'optional — routes CRITICAL to Slack',
        ],
        'commands' => [],
    ],

    'tracing' => [
        'description' => 'OpenTelemetry tracing with configurable samplers. Zero overhead when disabled.',
        'provides'    => [
            'TracingInterface' => 'startSpan(name), addAttribute(key, value), endSpan()',
            'NoOpTracer'       => 'Default — zero overhead, no-op implementation',
            'SamplingTracer'   => 'Probabilistic sampling: RatioSampler, AlwaysOnSampler, AlwaysOffSampler',
        ],
        'config' => [
            'sampler'          => 'ratio (default) | always-on | always-off',
            'ratio'            => 'float 0.0–1.0 (default: 0.1)',
            'disabled_modules' => 'array of module names to exclude from tracing',
            'trust_remote'     => 'bool — trust W3C traceparent header from upstream (default: false)',
        ],
        'commands' => [],
    ],

    'foundation' => [
        'description' => 'Health checks and worker mode service resetter.',
        'provides'    => [
            'HealthRegistry'   => 'Register HealthCheckInterface implementations. Exposed via /health/ready and /health/live.',
            'ServicesResetter' => 'Calls reset() on all ResettableInterface services after each request in worker mode.',
        ],
        'config'   => null,
        'commands' => [],
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
