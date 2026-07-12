<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Audit\DependencyInjection\VortosAuditConfig;
use Vortos\Audit\Enum\AuditSearchDriver;
use Vortos\Audit\Enum\FailureMode;

final class VortosAuditConfigTest extends TestCase
{
    public function test_defaults_match_the_documented_baseline(): void
    {
        $c = (new VortosAuditConfig())->toArray();

        self::assertTrue($c['strict']);
        self::assertFalse($c['async']);
        self::assertSame('vortos.audit', $c['consumer']);
        self::assertSame('block', $c['failure_mode']);
        self::assertSame(604800, $c['idempotency_ttl_seconds']);
        self::assertSame(730, $c['retention_platform_days']);
        self::assertSame(365, $c['retention_tenant_days']);
        self::assertSame('postgres_fts', $c['search_driver']);
        self::assertFalse($c['row_level_security']);
        self::assertFalse($c['auth_events_unify']);
        self::assertTrue($c['auth_events_scope_to_tenant']);
    }

    public function test_every_fluent_method_sets_its_value(): void
    {
        $c = (new VortosAuditConfig())
            ->strict(false)
            ->async(true)
            ->consumer('acme.audit')
            ->failureMode(FailureMode::Drop)
            ->idempotencyTtl('2 days')
            ->redisDsn('redis://cache:6379')
            ->retention(platform: 900, tenant: 400)
            ->retentionOverride('org-7', 30)
            ->retentionBatchSize(250)
            ->coldArchive(bucket: 'acme-audit', prefix: 'trail')
            ->search(AuditSearchDriver::External)
            ->rowLevelSecurity(true)
            ->authEvents(unify: true, scopeToTenantWhenKnown: false)
            ->toArray();

        self::assertFalse($c['strict']);
        self::assertTrue($c['async']);
        self::assertSame('acme.audit', $c['consumer']);
        self::assertSame('drop', $c['failure_mode']);
        self::assertSame(172800, $c['idempotency_ttl_seconds']);
        self::assertSame('redis://cache:6379', $c['redis_dsn']);
        self::assertSame(900, $c['retention_platform_days']);
        self::assertSame(400, $c['retention_tenant_days']);
        self::assertSame(['org-7' => 30], $c['retention_tenant_overrides']);
        self::assertSame(250, $c['retention_batch_size']);
        self::assertSame('acme-audit', $c['archive_bucket']);
        self::assertSame('trail', $c['archive_key_prefix']);
        self::assertSame('external', $c['search_driver']);
        self::assertTrue($c['row_level_security']);
        self::assertTrue($c['auth_events_unify']);
        self::assertFalse($c['auth_events_scope_to_tenant']);
    }

    public function test_idempotency_ttl_accepts_bare_seconds(): void
    {
        self::assertSame(90, (new VortosAuditConfig())->idempotencyTtl(90)->toArray()['idempotency_ttl_seconds']);
        self::assertSame(45, (new VortosAuditConfig())->idempotencyTtl('45')->toArray()['idempotency_ttl_seconds']);
    }

    public function test_hmac_key_is_resolved_from_referenced_env_and_excluded_from_array(): void
    {
        $env = 'VORTOS_AUDIT_HMAC_KEY_TEST_' . bin2hex(random_bytes(3));
        $_ENV[$env] = 'super-secret';

        $config = (new VortosAuditConfig())->hmacKeyFromSecret($env);

        self::assertSame('super-secret', $config->resolveHmacKey());
        self::assertArrayNotHasKey('hmac_key', $config->toArray(), 'the secret value must never appear in the serialised config');

        unset($_ENV[$env]);
    }
}
