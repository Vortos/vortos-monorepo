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
            'CREATE TABLE ' . self::TABLE . ' (id VARCHAR(36) NOT NULL, name VARCHAR(255) NOT NULL UNIQUE, description TEXT, enabled INTEGER NOT NULL DEFAULT 0, rules TEXT NOT NULL DEFAULT \'[]\', variants TEXT, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id))',
        );
        $this->storage = new DatabaseFlagStorage($this->connection, self::TABLE);
    }

    public function test_uses_injected_table_name(): void
    {
        $customTable = 'vortos_feature_flags';
        $this->connection->executeStatement(
            'CREATE TABLE ' . $customTable . ' (id VARCHAR(36) NOT NULL, name VARCHAR(255) NOT NULL UNIQUE, description TEXT, enabled INTEGER NOT NULL DEFAULT 0, rules TEXT NOT NULL DEFAULT \'[]\', variants TEXT, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id))',
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
