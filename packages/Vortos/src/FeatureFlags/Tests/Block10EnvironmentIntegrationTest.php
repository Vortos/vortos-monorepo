<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagEnvironmentState;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Resolution\EnvironmentScopedFlagResolver;
use Vortos\FeatureFlags\Storage\DatabaseFlagEnvironmentStateStorage;
use Vortos\FeatureFlags\Storage\DatabaseFlagStorage;

/**
 * End-to-end environment integration tests (Block 10).
 *
 * Uses two in-memory SQLite databases (definitions + env state), the real
 * resolver, and the real storage adapters — no mocks except the legacy upper-chain
 * which is irrelevant here. Validates the full definition/state split round-trip.
 */
final class Block10EnvironmentIntegrationTest extends TestCase
{
    private const FLAG_TABLE = 'feature_flags';
    private const ENV_TABLE  = 'feature_flag_environment_state';

    private \Doctrine\DBAL\Connection $connection;
    private DatabaseFlagStorage $flagStorage;
    private DatabaseFlagEnvironmentStateStorage $envStorage;
    private FlagScopeContext $scope;
    private EnvironmentScopedFlagResolver $resolver;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement(
            'CREATE TABLE ' . self::FLAG_TABLE . ' (
                id            VARCHAR(36)  NOT NULL,
                name          VARCHAR(255) NOT NULL UNIQUE,
                description   TEXT         NOT NULL DEFAULT \'\',
                enabled       INTEGER      NOT NULL DEFAULT 0,
                rules         TEXT         NOT NULL DEFAULT \'[]\',
                variants      TEXT,
                value_type    VARCHAR(16)  NOT NULL DEFAULT \'bool\',
                default_value TEXT,
                payload       TEXT,
                bucket_by     VARCHAR(32)  NOT NULL DEFAULT \'userId\',
                kind          VARCHAR(16)  NOT NULL DEFAULT \'release\',
                prerequisites TEXT,
                variant_rules TEXT,
                schedule      TEXT,
                required_scope VARCHAR(191),
                project_id    VARCHAR(191) NOT NULL DEFAULT \'default\',
                lifecycle     VARCHAR(16)  NOT NULL DEFAULT \'active\',
                owner         VARCHAR(191),
                expires_at    DATETIME,
                created_at    DATETIME     NOT NULL,
                updated_at    DATETIME     NOT NULL,
                PRIMARY KEY (id)
            )',
        );

        $this->connection->executeStatement(
            'CREATE TABLE ' . self::ENV_TABLE . ' (
                flag_id        VARCHAR(36)  NOT NULL,
                environment    VARCHAR(64)  NOT NULL,
                enabled        INTEGER      NOT NULL DEFAULT 0,
                rules          TEXT         NOT NULL DEFAULT \'[]\',
                variants       TEXT,
                variant_rules  TEXT,
                schedule       TEXT,
                payload        TEXT,
                required_scope VARCHAR(191),
                prerequisites  TEXT,
                updated_at     DATETIME     NOT NULL,
                PRIMARY KEY (flag_id, environment)
            )',
        );

        $this->flagStorage = new DatabaseFlagStorage($this->connection, self::FLAG_TABLE);
        $this->envStorage  = new DatabaseFlagEnvironmentStateStorage($this->connection, self::ENV_TABLE);
        $this->scope       = new FlagScopeContext();
        $this->resolver    = new EnvironmentScopedFlagResolver($this->flagStorage, $this->envStorage, $this->scope);
    }

    public function test_flag_with_env_state_resolves_correctly(): void
    {
        $flag = $this->makeFlag('dark-mode');
        $this->flagStorage->save($flag);
        $this->envStorage->save(new FlagEnvironmentState(
            flagId:        $flag->id,
            environment:   'production',
            enabled:       true,
            rules:         [],
            variants:      null,
            variantRules:  null,
            schedule:      null,
            payload:       null,
            requiredScope: null,
            prerequisites: [],
            updatedAt:     new \DateTimeImmutable(),
        ));

        $this->scope->withEnvironment('production');
        $resolved = $this->resolver->resolve('dark-mode', new \Vortos\FeatureFlags\FlagContext('u1'));

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->enabled);
        $this->assertSame('production', $resolved->environment);
    }

    public function test_enabling_in_staging_does_not_affect_production(): void
    {
        $flag = $this->makeFlag('checkout-v2');
        $this->flagStorage->save($flag);

        $this->envStorage->save(new FlagEnvironmentState(
            flagId: $flag->id, environment: 'production', enabled: false,
            rules: [], variants: null, variantRules: null, schedule: null,
            payload: null, requiredScope: null, prerequisites: [], updatedAt: new \DateTimeImmutable(),
        ));
        $this->envStorage->save(new FlagEnvironmentState(
            flagId: $flag->id, environment: 'staging', enabled: true,
            rules: [], variants: null, variantRules: null, schedule: null,
            payload: null, requiredScope: null, prerequisites: [], updatedAt: new \DateTimeImmutable(),
        ));

        $ctx = new \Vortos\FeatureFlags\FlagContext('u1');

        $this->scope->withEnvironment('production');
        $prodResolved = $this->resolver->resolve('checkout-v2', $ctx);
        $this->resolver->reset();

        $this->scope->withEnvironment('staging');
        $stagingResolved = $this->resolver->resolve('checkout-v2', $ctx);

        $this->assertFalse($prodResolved->enabled, 'production must not be affected by staging enable');
        $this->assertTrue($stagingResolved->enabled);
    }

    public function test_legacy_flag_no_env_state_resolves_in_production(): void
    {
        // Legacy Phase A/B flag: saved to flag_flags but has no env_state row.
        $flag = $this->makeFlag('legacy-feature', enabled: true);
        $this->flagStorage->save($flag);

        $this->scope->withEnvironment('production');
        $resolved = $this->resolver->resolve('legacy-feature', new \Vortos\FeatureFlags\FlagContext('u1'));

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->enabled, 'legacy flag must resolve from definition row in production');
    }

    public function test_legacy_flag_invisible_in_staging(): void
    {
        $flag = $this->makeFlag('legacy-feature', enabled: true);
        $this->flagStorage->save($flag);

        $this->scope->withEnvironment('staging');
        $resolved = $this->resolver->resolve('legacy-feature', new \Vortos\FeatureFlags\FlagContext('u1'));

        $this->assertNull($resolved, 'legacy flag must be invisible in non-production without env state');
    }

    public function test_run_as_switches_env_context_temporarily(): void
    {
        $flag = $this->makeFlag('my-flag');
        $this->flagStorage->save($flag);

        $this->envStorage->save(new FlagEnvironmentState(
            flagId: $flag->id, environment: 'production', enabled: false,
            rules: [], variants: null, variantRules: null, schedule: null,
            payload: null, requiredScope: null, prerequisites: [], updatedAt: new \DateTimeImmutable(),
        ));
        $this->envStorage->save(new FlagEnvironmentState(
            flagId: $flag->id, environment: 'dev', enabled: true,
            rules: [], variants: null, variantRules: null, schedule: null,
            payload: null, requiredScope: null, prerequisites: [], updatedAt: new \DateTimeImmutable(),
        ));

        $ctx = new \Vortos\FeatureFlags\FlagContext('u1');
        $this->scope->withEnvironment('production');

        $devEnabled = $this->scope->runAs('dev', function () use ($ctx): bool {
            $this->resolver->reset();
            return (bool) $this->resolver->resolve('my-flag', $ctx)?->enabled;
        });

        $this->resolver->reset();
        $prodFlag = $this->resolver->resolve('my-flag', $ctx);

        $this->assertTrue($devEnabled);
        $this->assertFalse($prodFlag->enabled);
        $this->assertSame('production', $this->scope->environment(), 'runAs must restore production scope');
    }

    public function test_compose_picks_env_state_over_definition_state(): void
    {
        // Definition has enabled=false; env state for 'production' has enabled=true.
        $flag = $this->makeFlag('toggle', enabled: false);
        $this->flagStorage->save($flag);
        $this->envStorage->save(new FlagEnvironmentState(
            flagId: $flag->id, environment: 'production', enabled: true,
            rules: [], variants: null, variantRules: null, schedule: null,
            payload: ['cfg' => 'x'], requiredScope: null, prerequisites: [], updatedAt: new \DateTimeImmutable(),
        ));

        $this->scope->withEnvironment('production');
        $resolved = $this->resolver->resolve('toggle', new \Vortos\FeatureFlags\FlagContext('u1'));

        $this->assertTrue($resolved->enabled, 'env state must override definition enabled field');
        $this->assertSame(['cfg' => 'x'], $resolved->payload, 'env state payload must be applied');
    }

    public function test_multiple_flags_multiple_envs_isolated(): void
    {
        $flag1 = $this->makeFlag('flag-a');
        $flag2 = $this->makeFlag('flag-b', id: '22222222-2222-4222-8222-222222222222');
        $this->flagStorage->save($flag1);
        $this->flagStorage->save($flag2);

        $this->envStorage->save(new FlagEnvironmentState(
            flagId: $flag1->id, environment: 'production', enabled: true,
            rules: [], variants: null, variantRules: null, schedule: null,
            payload: null, requiredScope: null, prerequisites: [], updatedAt: new \DateTimeImmutable(),
        ));
        $this->envStorage->save(new FlagEnvironmentState(
            flagId: $flag2->id, environment: 'production', enabled: false,
            rules: [], variants: null, variantRules: null, schedule: null,
            payload: null, requiredScope: null, prerequisites: [], updatedAt: new \DateTimeImmutable(),
        ));
        $this->envStorage->save(new FlagEnvironmentState(
            flagId: $flag1->id, environment: 'staging', enabled: false,
            rules: [], variants: null, variantRules: null, schedule: null,
            payload: null, requiredScope: null, prerequisites: [], updatedAt: new \DateTimeImmutable(),
        ));
        $this->envStorage->save(new FlagEnvironmentState(
            flagId: $flag2->id, environment: 'staging', enabled: true,
            rules: [], variants: null, variantRules: null, schedule: null,
            payload: null, requiredScope: null, prerequisites: [], updatedAt: new \DateTimeImmutable(),
        ));

        $ctx = new \Vortos\FeatureFlags\FlagContext('u1');

        $this->scope->withEnvironment('production');
        $allProd = $this->resolver->resolveAll($ctx);
        $this->resolver->reset();

        $this->scope->withEnvironment('staging');
        $allStaging = $this->resolver->resolveAll($ctx);

        $prodByName    = array_column($allProd, null, 'name');
        $stagingByName = array_column($allStaging, null, 'name');

        $this->assertTrue($prodByName['flag-a']->enabled);
        $this->assertFalse($prodByName['flag-b']->enabled);
        $this->assertFalse($stagingByName['flag-a']->enabled);
        $this->assertTrue($stagingByName['flag-b']->enabled);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function makeFlag(
        string $name,
        bool $enabled = false,
        string $id = '11111111-1111-4111-8111-111111111111',
    ): FeatureFlag {
        $now = new \DateTimeImmutable();
        return new FeatureFlag(
            id:          $id,
            name:        $name,
            description: '',
            enabled:     $enabled,
            rules:       [],
            variants:    null,
            createdAt:   $now,
            updatedAt:   $now,
        );
    }
}
