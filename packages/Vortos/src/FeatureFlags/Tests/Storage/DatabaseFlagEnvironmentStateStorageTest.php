<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Storage;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FlagEnvironmentState;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\Storage\DatabaseFlagEnvironmentStateStorage;

final class DatabaseFlagEnvironmentStateStorageTest extends TestCase
{
    private const TABLE = 'feature_flag_environment_state';

    private \Doctrine\DBAL\Connection $connection;
    private DatabaseFlagEnvironmentStateStorage $storage;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE ' . self::TABLE . ' (
                flag_id       VARCHAR(36)  NOT NULL,
                environment   VARCHAR(64)  NOT NULL,
                enabled       INTEGER      NOT NULL DEFAULT 0,
                rules         TEXT         NOT NULL DEFAULT \'[]\',
                variants      TEXT,
                variant_rules TEXT,
                schedule      TEXT,
                payload       TEXT,
                required_scope VARCHAR(191),
                prerequisites TEXT,
                updated_at    DATETIME     NOT NULL,
                PRIMARY KEY (flag_id, environment)
            )',
        );

        $this->storage = new DatabaseFlagEnvironmentStateStorage($this->connection, self::TABLE);
    }

    public function test_find_for_flag_returns_null_when_no_state(): void
    {
        $this->assertNull($this->storage->findForFlag('flag-1', 'production'));
    }

    public function test_save_and_find_for_flag(): void
    {
        $state = $this->makeState('flag-1', 'production', true);
        $this->storage->save($state);

        $found = $this->storage->findForFlag('flag-1', 'production');

        $this->assertNotNull($found);
        $this->assertSame('flag-1', $found->flagId);
        $this->assertSame('production', $found->environment);
        $this->assertTrue($found->enabled);
    }

    public function test_save_is_idempotent_upsert(): void
    {
        $this->storage->save($this->makeState('flag-1', 'production', false));
        $this->storage->save($this->makeState('flag-1', 'production', true)); // upsert

        $found = $this->storage->findForFlag('flag-1', 'production');
        $this->assertTrue($found->enabled, 'second save should win (upsert)');
    }

    public function test_find_all_for_env_returns_only_matching_env(): void
    {
        $this->storage->save($this->makeState('flag-1', 'production', true));
        $this->storage->save($this->makeState('flag-2', 'production', false));
        $this->storage->save($this->makeState('flag-1', 'staging', false));

        $states = $this->storage->findAllForEnv('production');

        $this->assertCount(2, $states);
        $this->assertArrayHasKey('flag-1', $states);
        $this->assertArrayHasKey('flag-2', $states);
        $this->assertArrayNotHasKey('flag-3', $states);
    }

    public function test_find_all_for_env_is_keyed_by_flag_id(): void
    {
        $this->storage->save($this->makeState('id-abc', 'staging', true));

        $states = $this->storage->findAllForEnv('staging');

        $this->assertArrayHasKey('id-abc', $states);
    }

    public function test_environment_isolation(): void
    {
        $this->storage->save($this->makeState('flag-1', 'production', true));
        $this->storage->save($this->makeState('flag-1', 'dev', false));

        $prod = $this->storage->findForFlag('flag-1', 'production');
        $dev  = $this->storage->findForFlag('flag-1', 'dev');

        $this->assertTrue($prod->enabled);
        $this->assertFalse($dev->enabled);
    }

    public function test_find_all_for_env_empty_for_new_env(): void
    {
        $this->storage->save($this->makeState('flag-1', 'production', true));

        $states = $this->storage->findAllForEnv('canary');
        $this->assertSame([], $states);
    }

    public function test_delete_removes_state(): void
    {
        $this->storage->save($this->makeState('flag-1', 'production', true));
        $this->storage->delete('flag-1', 'production');

        $this->assertNull($this->storage->findForFlag('flag-1', 'production'));
    }

    public function test_delete_only_removes_target_env(): void
    {
        $this->storage->save($this->makeState('flag-1', 'production', true));
        $this->storage->save($this->makeState('flag-1', 'staging', false));
        $this->storage->delete('flag-1', 'staging');

        $this->assertNotNull($this->storage->findForFlag('flag-1', 'production'));
        $this->assertNull($this->storage->findForFlag('flag-1', 'staging'));
    }

    public function test_delete_nonexistent_is_a_noop(): void
    {
        $this->expectNotToPerformAssertions();
        $this->storage->delete('nonexistent-flag', 'production');
    }

    public function test_rules_are_round_tripped(): void
    {
        $rule  = new FlagRule(type: FlagRule::TYPE_PERCENTAGE, percentage: 42);
        $state = new FlagEnvironmentState(
            flagId:        'flag-r',
            environment:   'production',
            enabled:       true,
            rules:         [$rule],
            variants:      null,
            variantRules:  null,
            schedule:      null,
            payload:       null,
            requiredScope: null,
            prerequisites: [],
            updatedAt:     new \DateTimeImmutable('2026-06-21T00:00:00Z'),
        );

        $this->storage->save($state);
        $found = $this->storage->findForFlag('flag-r', 'production');

        $this->assertCount(1, $found->rules);
        $this->assertSame(FlagRule::TYPE_PERCENTAGE, $found->rules[0]->type);
        $this->assertSame(42, $found->rules[0]->percentage);
    }

    public function test_payload_is_round_tripped(): void
    {
        $state = new FlagEnvironmentState(
            flagId:        'flag-p',
            environment:   'production',
            enabled:       true,
            rules:         [],
            variants:      null,
            variantRules:  null,
            schedule:      null,
            payload:       ['theme' => 'dark', 'limit' => 5],
            requiredScope: null,
            prerequisites: [],
            updatedAt:     new \DateTimeImmutable(),
        );

        $this->storage->save($state);
        $found = $this->storage->findForFlag('flag-p', 'production');

        $this->assertSame(['theme' => 'dark', 'limit' => 5], $found->payload);
    }

    public function test_required_scope_is_round_tripped(): void
    {
        $state = new FlagEnvironmentState(
            flagId:        'flag-s',
            environment:   'production',
            enabled:       false,
            rules:         [],
            variants:      null,
            variantRules:  null,
            schedule:      null,
            payload:       null,
            requiredScope: 'resource.read.global',
            prerequisites: [],
            updatedAt:     new \DateTimeImmutable(),
        );

        $this->storage->save($state);
        $found = $this->storage->findForFlag('flag-s', 'production');

        $this->assertSame('resource.read.global', $found->requiredScope);
    }

    public function test_save_many_flags_returns_all_in_bulk_load(): void
    {
        for ($i = 1; $i <= 50; $i++) {
            $this->storage->save($this->makeState("flag-$i", 'production', $i % 2 === 0));
        }

        $states = $this->storage->findAllForEnv('production');
        $this->assertCount(50, $states);
    }

    private function makeState(string $flagId, string $environment, bool $enabled): FlagEnvironmentState
    {
        return new FlagEnvironmentState(
            flagId:        $flagId,
            environment:   $environment,
            enabled:       $enabled,
            rules:         [],
            variants:      null,
            variantRules:  null,
            schedule:      null,
            payload:       null,
            requiredScope: null,
            prerequisites: [],
            updatedAt:     new \DateTimeImmutable('2026-06-21T00:00:00Z'),
        );
    }
}
