<?php

declare(strict_types=1);

namespace Vortos\Audit\DependencyInjection;

use Vortos\Audit\Enum\AuditSearchDriver;
use Vortos\Audit\Enum\FailureMode;

/**
 * Fluent configuration object for vortos-audit.
 *
 * Loaded via `(require config/audit.php)($config)` in {@see AuditExtension::loadConfig()},
 * matching the Scheduler/Messaging convention. Every knob is a typed method with a sensible
 * default identical to the env-var/array defaults this class replaces — no config file is
 * required for basic usage.
 *
 * ## Standard usage
 *
 * Create config/audit.php in your project:
 *
 *   return static function (VortosAuditConfig $config): void {
 *       $config
 *           ->async(true)
 *           ->authEvents(unify: true)
 *           ->hmacKeyFromSecret('VORTOS_AUDIT_HMAC_KEY')
 *           ->rowLevelSecurity(true)
 *           ->retention(platform: 730, tenant: 365)
 *           ->coldArchive(bucket: 'sqoura-audit-archive', prefix: 'audit-archive');
 *   };
 *
 * Env-specific overrides go in config/{env}/audit.php, loaded after the base file.
 *
 * SECURITY: the HMAC key is referenced BY ENV NAME only (`hmacKeyFromSecret`) so the secret
 * itself stays in the sealed environment and never lands in a committed config file.
 */
final class VortosAuditConfig
{
    private bool $strict;
    private bool $async;
    private string $consumer;
    private FailureMode $failureMode;
    private string $hmacKeyEnv;
    private int $idempotencyTtlSeconds;
    private string $redisDsn;
    private int $retentionPlatformDays;
    private int $retentionTenantDays;
    /** @var array<string, int> */
    private array $retentionTenantOverrides;
    private int $retentionBatchSize;
    private string $archiveBucket;
    private string $archiveKeyPrefix;
    private string $exportConsumer;
    private string $exportKeyPrefix;
    private int $exportArtifactRetentionDays;
    private int $exportDownloadUrlTtlSeconds;
    private int $exportPageSize;
    private AuditSearchDriver $searchDriver;
    private bool $rowLevelSecurity;
    private bool $authEventsUnify;
    private bool $authEventsScopeToTenantWhenKnown;

    public function __construct()
    {
        $this->strict                            = true;
        $this->async                             = false;
        $this->consumer                          = 'vortos.audit';
        $this->failureMode                       = FailureMode::Block;
        $this->hmacKeyEnv                        = 'VORTOS_AUDIT_HMAC_KEY';
        $this->idempotencyTtlSeconds             = 604800; // 7d
        $this->redisDsn                          = (string) ($_ENV['VORTOS_AUDIT_REDIS_DSN'] ?? getenv('VORTOS_AUDIT_REDIS_DSN') ?: '');
        $this->retentionPlatformDays             = 730;
        $this->retentionTenantDays               = 365;
        $this->retentionTenantOverrides          = [];
        $this->retentionBatchSize                = 1000;
        $this->archiveBucket                     = '';
        $this->archiveKeyPrefix                  = 'audit-archive';
        $this->exportConsumer                    = 'vortos.audit.export';
        $this->exportKeyPrefix                   = 'audit-exports';
        $this->exportArtifactRetentionDays       = 7;
        $this->exportDownloadUrlTtlSeconds       = 900; // 15m
        $this->exportPageSize                    = 500;
        $this->searchDriver                      = AuditSearchDriver::PostgresFts;
        $this->rowLevelSecurity                  = false;
        $this->authEventsUnify                   = false;
        $this->authEventsScopeToTenantWhenKnown  = true;
    }

    /** Strict vocabulary: reject unknown actions instead of recording them at Normal. */
    public function strict(bool $strict = true): static
    {
        $this->strict = $strict;
        return $this;
    }

    /**
     * Enable async ingestion: record() enqueues onto the bus (→ outbox → Kafka) and the
     * consumer worker appends the chain. When true the app MUST declare the consumer
     * pipeline (see {@see consumer()}).
     */
    public function async(bool $async = true): static
    {
        $this->async = $async;
        return $this;
    }

    /** Logical consumer/topic name the async pipeline uses. Must match the app's #[MessagingConfig]. */
    public function consumer(string $name): static
    {
        $this->consumer = $name;
        return $this;
    }

    /** What the async recorder does when it cannot enqueue: Block (compliance) or Drop (availability). */
    public function failureMode(FailureMode $mode): static
    {
        $this->failureMode = $mode;
        return $this;
    }

    /**
     * Name of the env var holding the HMAC signing key. The value is resolved at container
     * build time and never stored in config — keep the secret in the sealed environment.
     */
    public function hmacKeyFromSecret(string $envName): static
    {
        $this->hmacKeyEnv = $envName;
        return $this;
    }

    /**
     * TTL for the ingestion idempotency key. Accepts a human duration ('7 days', '48 hours')
     * or a bare integer number of seconds.
     */
    public function idempotencyTtl(string|int $duration): static
    {
        $this->idempotencyTtlSeconds = self::toSeconds($duration);
        return $this;
    }

    /** Redis DSN for the cross-process ingestion idempotency guard. Empty → process-local guard. */
    public function redisDsn(string $dsn): static
    {
        $this->redisDsn = $dsn;
        return $this;
    }

    /** Default retention windows (days). 0 = never purge. Per-tenant overrides via {@see retentionOverride()}. */
    public function retention(int $platform, int $tenant): static
    {
        $this->retentionPlatformDays = \max(0, $platform);
        $this->retentionTenantDays   = \max(0, $tenant);
        return $this;
    }

    /** Override the retention window (days) for one tenant. 0 = never purge that tenant. */
    public function retentionOverride(string $tenantId, int $days): static
    {
        $this->retentionTenantOverrides[$tenantId] = \max(0, $days);
        return $this;
    }

    /** Rows moved per archive batch by the retention sweeper. */
    public function retentionBatchSize(int $rows): static
    {
        $this->retentionBatchSize = \max(1, $rows);
        return $this;
    }

    /** Cold-archive object-store target for aged records. Bucket is informational for the app's ObjectStore wiring. */
    public function coldArchive(string $bucket = '', string $prefix = 'audit-archive'): static
    {
        $this->archiveBucket    = $bucket;
        $this->archiveKeyPrefix = $prefix;
        return $this;
    }

    /**
     * Async export tuning. Exports run on their OWN consumer (isolated from ingestion so a
     * huge export can't stall the append path); the artifact is streamed to the object store
     * under {@see $keyPrefix} and stays downloadable for {@see $artifactRetentionDays} before
     * the GC command removes it; each status request mints a fresh presigned URL valid for
     * {@see $downloadUrlTtlSeconds}.
     */
    public function exports(
        ?string $consumer = null,
        ?string $keyPrefix = null,
        ?int    $artifactRetentionDays = null,
        ?int    $downloadUrlTtlSeconds = null,
        ?int    $pageSize = null,
    ): static {
        if ($consumer !== null) {
            $this->exportConsumer = $consumer;
        }
        if ($keyPrefix !== null) {
            $this->exportKeyPrefix = $keyPrefix;
        }
        if ($artifactRetentionDays !== null) {
            $this->exportArtifactRetentionDays = \max(1, $artifactRetentionDays);
        }
        if ($downloadUrlTtlSeconds !== null) {
            $this->exportDownloadUrlTtlSeconds = \max(60, $downloadUrlTtlSeconds);
        }
        if ($pageSize !== null) {
            $this->exportPageSize = \max(1, $pageSize);
        }
        return $this;
    }

    /** Which search index backs free-text queries. Default = Postgres FTS. */
    public function search(AuditSearchDriver $driver): static
    {
        $this->searchDriver = $driver;
        return $this;
    }

    /**
     * Enable Postgres row-level security on audit_events as a DB-enforced tenant-isolation
     * backstop. No-op off Postgres. The app must set the per-request `app.current_tenant` GUC.
     */
    public function rowLevelSecurity(bool $enabled = true): static
    {
        $this->rowLevelSecurity = $enabled;
        return $this;
    }

    /**
     * Fold auth/security events into the unified store.
     *
     * @param bool $unify                  route auth events through AuditTrail into audit_events
     * @param bool $scopeToTenantWhenKnown when the org is resolvable, record Scope::Tenant; else Scope::Platform
     */
    public function authEvents(bool $unify = true, bool $scopeToTenantWhenKnown = true): static
    {
        $this->authEventsUnify                  = $unify;
        $this->authEventsScopeToTenantWhenKnown = $scopeToTenantWhenKnown;
        return $this;
    }

    /**
     * Resolve the HMAC key value from its configured env var, falling back to the legacy
     * VORTOS_AUDIT_HMAC_KEY. Kept out of {@see toArray()} so the secret is only ever read at
     * container build time.
     */
    public function resolveHmacKey(): string
    {
        $fromEnv = static fn (string $name): string => (string) ($_ENV[$name] ?? getenv($name) ?: '');
        $value   = $fromEnv($this->hmacKeyEnv);

        return $value !== '' ? $value : $fromEnv('VORTOS_AUDIT_HMAC_KEY');
    }

    /**
     * @internal Consumed by AuditExtension. Excludes the resolved HMAC secret by design.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'strict'                        => $this->strict,
            'async'                         => $this->async,
            'consumer'                      => $this->consumer,
            'failure_mode'                  => $this->failureMode->value,
            'hmac_key_env'                  => $this->hmacKeyEnv,
            'idempotency_ttl_seconds'       => $this->idempotencyTtlSeconds,
            'redis_dsn'                     => $this->redisDsn,
            'retention_platform_days'       => $this->retentionPlatformDays,
            'retention_tenant_days'         => $this->retentionTenantDays,
            'retention_tenant_overrides'    => $this->retentionTenantOverrides,
            'retention_batch_size'          => $this->retentionBatchSize,
            'archive_bucket'                => $this->archiveBucket,
            'archive_key_prefix'            => $this->archiveKeyPrefix,
            'export_consumer'               => $this->exportConsumer,
            'export_key_prefix'             => $this->exportKeyPrefix,
            'export_artifact_retention_days' => $this->exportArtifactRetentionDays,
            'export_download_url_ttl_seconds' => $this->exportDownloadUrlTtlSeconds,
            'export_page_size'              => $this->exportPageSize,
            'search_driver'                 => $this->searchDriver->value,
            'row_level_security'            => $this->rowLevelSecurity,
            'auth_events_unify'             => $this->authEventsUnify,
            'auth_events_scope_to_tenant'   => $this->authEventsScopeToTenantWhenKnown,
        ];
    }

    private static function toSeconds(string|int $duration): int
    {
        if (is_int($duration)) {
            return \max(1, $duration);
        }
        if (ctype_digit($duration)) {
            return \max(1, (int) $duration);
        }
        $seconds = strtotime($duration, 0);
        return $seconds !== false && $seconds > 0 ? $seconds : 604800;
    }
}
