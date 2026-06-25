<?php

declare(strict_types=1);

return [
    'performance' => [
        'Use FrankenPHP worker mode — the DI container, compiled cache, and static data are built once per process. All subsequent requests skip the bootstrap cost.',
        'Compile the DI container in CI — never leave container compilation to the first production request.',
        'Keep the DBAL Connection shared (setShared: true) — a second instance creates a second transaction context and breaks atomicity.',
        'Use TaggedCacheInterface for cache invalidation — avoid scanning or iterating over cache keys at runtime.',
        'Projection handlers must be fast — they run synchronously in the consumer loop. Offload heavy work to a subsequent command or async job.',
        'Batch outbox relay — the OutboxRelayWorker processes up to 100 messages per poll by default. Increase batch size for high-throughput event streams.',
        'In worker mode, call cleanUp() after every request — ServicesResetter flushes per-request state (UnitOfWork, identity, correlation ID, query log).',
        'Index the outbox table on (status, created_at) WHERE status = \'pending\' — the relay polls this query on every cycle.',
        'Use Redis for idempotency keys (CommandBus) and authorization cache — in-memory does not survive worker restarts.',
    ],

    'security' => [
        'Never put raw secrets in .env for production — use VaultSecretsProvider or AwsSsmSecretsProvider (vortos-security) for app-config lookups, or vortos-secrets (if installed) for anything needing envelope encryption, off-host key custody, or rotation.',
        'If a value is a SecretValue (vortos-secrets), call reveal() as late as possible and never store the revealed string — hold the wrapped value as long as you can, pass the raw string straight into the call that needs it, then let it go out of scope.',
        'Enable SecurityHeadersMiddleware in production — HSTS, CSP, X-Frame-Options, X-Content-Type-Options.',
        'Enable CsrfMiddleware for all state-changing web routes (POST/PUT/PATCH/DELETE that originate from a browser).',
        'Use RequestSignatureMiddleware for all webhook endpoints — verify HMAC before processing the payload.',
        'JWT secret (HS256) must be at least 64 hex characters — use bin2hex(random_bytes(32)). For multi-service architectures use RS256: configure ->algorithm("RS256") with ->privateKeyPath() / ->publicKeyPath() so verifying services only hold the public key.',
        'Rotate JWT secrets without downtime — issue new tokens with the new secret, let old tokens expire naturally.',
        'Use DataMaskingProcessor to strip PII (email, phone, card numbers) from log entries before they reach Sentry/Slack.',
        'Rate limiting and quota enforcement live in AuthPackage — use RedisRateLimitStore in production, not InMemory.',
        'API keys for M2M calls go through ApiKeyAuthMiddleware — never accept raw API keys in query strings, only headers.',
        'HEALTH_DETAILS defaults to "never" — set HEALTH_DETAILS=token and a strong HEALTH_TOKEN in production to allow monitored access to detailed health output without exposing it publicly.',
        'Set HEALTH_EXPOSE_ERRORS=false in production — the /health endpoints must not leak stack traces.',
    ],

    'testing' => [
        'Use InMemory drivers for all unit tests — InMemoryEventBus, InMemoryCache, InMemoryCqrsBus. No Redis, Kafka, or Mongo required.',
        'Test projection handlers in isolation — dispatch an event, assert the read model was upserted correctly.',
        'Test command handlers against a real (test) database when testing persistence — integration tests are more valuable than mocked ones for the write side.',
        'Use the ServicesResetter between tests in integration suites — same as cleanUp() in production, resets state between test cases.',
        'Assert domain events from aggregates: $envelopes = $aggregate->pullDomainEvents() — returns EventEnvelope[]. Assert $envelope->payload for the POPO data, $envelope->aggregateId, $envelope->payloadType, etc. Never assert Kafka messages directly in unit tests.',
        'For authorization tests: seed roles and permissions, then assert PolicyEngine::can() returns the expected result.',
        'Use --dry-run on vortos:migrate:fresh in CI to verify migration scripts before applying them.',
        'Test outbox writes: after a command, assert that vortos_outbox has a pending row with the expected payload_type (FQCN) and payload JSON.',
    ],

    'worker_mode' => [
        'Register ServicesResetter in bootstrap — it calls reset() on all ResettableInterface services after each request.',
        'Every stateful service (UnitOfWork, CurrentUserProvider, correlation ID store, query log) must implement ResettableInterface.',
        'Do not use static properties to cache per-request data — they persist across requests in worker mode.',
        'Connection pooling is safe in worker mode — DBAL Connection is shared per-process, not per-request.',
        'Monitor memory in long-running workers — use a max-requests option to recycle the process if memory grows unboundedly.',
        'FrankenPHP worker mode initialization runs once: DI container build, Kafka connection, Redis connection. Request handling is the hot path.',
    ],

    'kafka' => [
        'Consumer group IDs should be per-service, not per-handler — one group per microservice/context boundary.',
        'Projection handlers must be idempotent (upsert, not insert) — Kafka redelivers on consumer restart or partition rebalance.',
        'Use the dead letter queue (vortos_failed_messages) for poison pills — do not let a bad message block a partition indefinitely.',
        'Configure retry policies per consumer — exponential backoff with jitter prevents thundering herd on recovery.',
        'The outbox pattern eliminates the dual-write problem — domain write and event write are in the same DB transaction.',
        'Do not produce directly to Kafka from a handler — always go through EventBus → outbox → relay. Direct production has no delivery guarantee if Kafka is temporarily unavailable.',
    ],

    'deployment_and_ops' => [
        'Before writing ANY custom deploy script, CI workflow, Terraform file, backup cron, or alerting integration, call get_module_docs / list_project_modules first — there is very likely a vortos:* command or installable package that already does it.',
        'Most deployment/ops packages (vortos-deploy, vortos-secrets, vortos-backup, vortos-alerts, vortos-pipeline, vortos-release, vortos-iac, vortos-analytics, vortos-health) are OPT-IN — installed only if explicitly composer-required. Check list_project_modules / composer.lock before assuming any of them exist in a given project.',
        'Never hand-edit a generated artifact: CI workflows come from `pipeline:generate` (vortos-pipeline), Terraform from `vortos:iac:export` (vortos-iac). Change the source model/declaration and regenerate — wire `pipeline:verify` / `vortos:iac:export --check` into CI so a hand-edit is caught the same run it lands.',
        'Every Ops Kit driver port (DeployTargetInterface, BackupTargetInterface, NotifierInterface, MetricsSinkInterface, etc.) declares capabilities() — check CapabilityDescriptor::supports() or rely on CapabilityValidator::assertSatisfies() before assuming a driver supports an operation. A driver that does not support something MUST throw UnsupportedCapabilityException, never silently no-op.',
        'deploy:rollback and vortos:iac plans can legitimately REFUSE (RollbackRefusedException, PlanStaleException, DestructiveChangeRefusedException, PolicyViolationException) — that is the fail-closed design working as intended, not a bug to route around. Read the refusal reason; it names the exact fix.',
        'Choose FailClosed vs FailOpen deliberately per use case, not by copying a default: FailClosed for anything security-relevant (login lockout), FailOpen for general throughput limiting where availability matters more than strict enforcement during a backing-store outage.',
        'Reach for vortos:auth:revoke-all-tokens (global min-iat) only for genuine incident response (leaked key, suspected mass compromise) — it logs out every session for every user. Use per-user token-storage revocation for routine logout/password-change.',
    ],
];
