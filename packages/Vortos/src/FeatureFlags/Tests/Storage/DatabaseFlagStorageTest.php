<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Storage;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\Storage\DatabaseFlagStorage;

final class DatabaseFlagStorageTest extends TestCase
{
    private const TABLE = 'feature_flags';

    private \Doctrine\DBAL\Connection $connection;
    private DatabaseFlagStorage $storage;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE ' . self::TABLE . ' (id VARCHAR(36) NOT NULL, name VARCHAR(255) NOT NULL UNIQUE, description TEXT, enabled INTEGER NOT NULL DEFAULT 0, rules TEXT NOT NULL DEFAULT \'[]\', variants TEXT, value_type VARCHAR(16) NOT NULL DEFAULT \'bool\', default_value TEXT, payload TEXT, bucket_by VARCHAR(32) NOT NULL DEFAULT \'userId\', kind VARCHAR(16) NOT NULL DEFAULT \'release\', prerequisites TEXT, variant_rules TEXT, schedule TEXT, required_scope VARCHAR(191), project_id VARCHAR(191) NOT NULL DEFAULT \'default\', lifecycle VARCHAR(16) NOT NULL DEFAULT \'active\', owner VARCHAR(191), expires_at DATETIME, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id))',
        );
        $this->storage = new DatabaseFlagStorage($this->connection, self::TABLE);
    }

    public function test_uses_injected_table_name(): void
    {
        $customTable = 'vortos_feature_flags';
        $this->connection->executeStatement(
            'CREATE TABLE ' . $customTable . ' (id VARCHAR(36) NOT NULL, name VARCHAR(255) NOT NULL UNIQUE, description TEXT, enabled INTEGER NOT NULL DEFAULT 0, rules TEXT NOT NULL DEFAULT \'[]\', variants TEXT, value_type VARCHAR(16) NOT NULL DEFAULT \'bool\', default_value TEXT, payload TEXT, bucket_by VARCHAR(32) NOT NULL DEFAULT \'userId\', kind VARCHAR(16) NOT NULL DEFAULT \'release\', prerequisites TEXT, variant_rules TEXT, schedule TEXT, required_scope VARCHAR(191), project_id VARCHAR(191) NOT NULL DEFAULT \'default\', lifecycle VARCHAR(16) NOT NULL DEFAULT \'active\', owner VARCHAR(191), expires_at DATETIME, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id))',
        );

        $customStorage = new DatabaseFlagStorage($this->connection, $customTable);
        $customStorage->save($this->makeFlag('beta'));

        $this->assertCount(1, $customStorage->findAll());
        $this->assertCount(0, $this->storage->findAll());
    }

    public function test_find_all_returns_empty_when_no_flags(): void
    {
        $this->assertSame([], $this->storage->findAll());
    }

    public function test_save_and_find_all_returns_persisted_flags(): void
    {
        $this->storage->save($this->makeFlag('dark-mode'));
        $this->storage->save($this->makeFlag('new-checkout'));

        $flags = $this->storage->findAll();

        $this->assertCount(2, $flags);
        $names = array_map(fn(FeatureFlag $f) => $f->name, $flags);
        sort($names);
        $this->assertSame(['dark-mode', 'new-checkout'], $names);
    }

    public function test_find_by_name_returns_matching_flag(): void
    {
        $this->storage->save($this->makeFlag('dark-mode'));

        $flag = $this->storage->findByName('dark-mode');

        $this->assertNotNull($flag);
        $this->assertSame('dark-mode', $flag->name);
    }

    public function test_find_by_name_returns_null_when_not_found(): void
    {
        $this->assertNull($this->storage->findByName('non-existent'));
    }

    public function test_save_is_upsert_by_name(): void
    {
        $flag = $this->makeFlag('dark-mode', enabled: false);
        $this->storage->save($flag);

        $updated = new FeatureFlag(
            id:          $flag->id,
            name:        'dark-mode',
            description: (string) 'Updated description',
            enabled:     true,
            rules:       [],
            variants:    null,
            createdAt:   $flag->createdAt,
            updatedAt:   new \DateTimeImmutable(),
        );
        $this->storage->save($updated);

        $found = $this->storage->findByName('dark-mode');
        $this->assertTrue($found->enabled);
        $this->assertSame('Updated description', $found->description);
        $this->assertCount(1, $this->storage->findAll());
    }

    public function test_delete_removes_flag(): void
    {
        $this->storage->save($this->makeFlag('dark-mode'));
        $this->storage->delete('dark-mode');

        $this->assertNull($this->storage->findByName('dark-mode'));
    }

    public function test_delete_non_existent_is_a_noop(): void
    {
        $this->storage->delete('non-existent');
        $this->addToAssertionCount(1);
    }

    public function test_round_trips_typed_values_and_payload(): void
    {
        $now  = new \DateTimeImmutable('2024-01-01 00:00:00');
        $json = new FeatureFlag(
            id: bin2hex(random_bytes(16)),
            name: 'pricing-config',
            description: 'remote config',
            enabled: true,
            rules: [],
            variants: null,
            createdAt: $now,
            updatedAt: $now,
            valueType: \Vortos\FeatureFlags\FlagValueType::Json,
            defaultValue: \Vortos\FeatureFlags\FlagValue::json(['tier' => 'free']),
            payload: ['tier' => 'pro', 'seats' => 5],
        );
        $this->storage->save($json);

        $loaded = $this->storage->findByName('pricing-config');
        $this->assertNotNull($loaded);
        $this->assertSame(\Vortos\FeatureFlags\FlagValueType::Json, $loaded->valueType);
        $this->assertSame(['tier' => 'free'], $loaded->defaultValue()->asJson());
        $this->assertSame(['tier' => 'pro', 'seats' => 5], $loaded->payload);

        $number = new FeatureFlag(
            id: bin2hex(random_bytes(16)),
            name: 'rate-limit',
            description: '',
            enabled: true,
            rules: [],
            variants: null,
            createdAt: $now,
            updatedAt: $now,
            valueType: \Vortos\FeatureFlags\FlagValueType::Number,
            defaultValue: \Vortos\FeatureFlags\FlagValue::number(100),
        );
        $this->storage->save($number);
        $this->assertSame(100.0, $this->storage->findByName('rate-limit')->defaultValue()->asNumber());
    }

    public function test_legacy_row_without_new_columns_hydrates_as_boolean(): void
    {
        // A table predating Block 1 (no value_type/default_value/payload columns).
        $legacyTable = 'legacy_flags';
        $this->connection->executeStatement(
            'CREATE TABLE ' . $legacyTable . ' (id VARCHAR(36) NOT NULL, name VARCHAR(255) NOT NULL UNIQUE, description TEXT, enabled INTEGER NOT NULL DEFAULT 0, rules TEXT NOT NULL DEFAULT \'[]\', variants TEXT, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id))',
        );
        $this->connection->executeStatement(
            'INSERT INTO ' . $legacyTable . ' (id, name, description, enabled, rules, variants, created_at, updated_at)
             VALUES (:id, :name, \'\', 1, \'[]\', NULL, :ts, :ts)',
            ['id' => bin2hex(random_bytes(16)), 'name' => 'old-flag', 'ts' => '2023-01-01 00:00:00'],
        );

        $legacyStorage = new DatabaseFlagStorage($this->connection, $legacyTable);
        $flag          = $legacyStorage->findByName('old-flag');

        $this->assertNotNull($flag);
        $this->assertSame(\Vortos\FeatureFlags\FlagValueType::Bool, $flag->valueType);
        $this->assertFalse($flag->defaultValue()->asBool());
        $this->assertNull($flag->payload);
        $this->assertTrue($flag->enabled);
    }

    private function makeFlag(string $name, bool $enabled = true): FeatureFlag
    {
        return new FeatureFlag(
            id:          bin2hex(random_bytes(16)),
            name:        $name,
            description: '',
            enabled:     $enabled,
            rules:       [],
            variants:    null,
            createdAt:   new \DateTimeImmutable('2024-01-01'),
            updatedAt:   new \DateTimeImmutable('2024-01-01'),
        );
    }
}
