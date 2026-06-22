<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Storage;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\Segment;
use Vortos\FeatureFlags\Storage\DatabaseSegmentStorage;

final class DatabaseSegmentStorageTest extends TestCase
{
    private const TABLE = 'feature_flag_segments';

    private \Doctrine\DBAL\Connection $connection;
    private DatabaseSegmentStorage $storage;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE ' . self::TABLE . ' (id VARCHAR(36) NOT NULL, name VARCHAR(255) NOT NULL UNIQUE, description TEXT, rules TEXT NOT NULL DEFAULT \'[]\', project_id VARCHAR(191) NOT NULL DEFAULT \'default\', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id))',
        );
        $this->storage = new DatabaseSegmentStorage($this->connection, self::TABLE);
    }

    public function test_round_trips_segment_with_rules(): void
    {
        $now     = new \DateTimeImmutable('2024-01-01 00:00:00');
        $segment = new Segment(
            id: 'seg-1',
            name: 'beta-testers',
            description: 'opted-in beta users',
            rules: [
                FlagRule::group(FlagRule::CMB_OR, [
                    new FlagRule(FlagRule::TYPE_USERS, users: ['u1', 'u2']),
                    new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'beta', operator: FlagRule::OP_EQUALS, value: 'true'),
                ]),
            ],
            createdAt: $now,
            updatedAt: $now,
        );

        $this->storage->save($segment);
        $loaded = $this->storage->findByName('beta-testers');

        $this->assertNotNull($loaded);
        $this->assertSame('beta-testers', $loaded->name);
        $this->assertSame('opted-in beta users', $loaded->description);
        $this->assertCount(1, $loaded->rules);
        $this->assertSame(FlagRule::TYPE_GROUP, $loaded->rules[0]->type);
        $this->assertCount(2, $loaded->rules[0]->children);
    }

    public function test_save_is_upsert_by_name(): void
    {
        $now = new \DateTimeImmutable('2024-01-01');
        $this->storage->save(new Segment('seg-1', 'aud', 'v1', [], $now, $now));
        $this->storage->save(new Segment('seg-1', 'aud', 'v2', [], $now, new \DateTimeImmutable()));

        $this->assertCount(1, $this->storage->findAll());
        $this->assertSame('v2', $this->storage->findByName('aud')->description);
    }

    public function test_find_by_name_returns_null_when_absent(): void
    {
        $this->assertNull($this->storage->findByName('nope'));
    }

    public function test_delete_removes_segment(): void
    {
        $now = new \DateTimeImmutable('2024-01-01');
        $this->storage->save(new Segment('seg-1', 'aud', '', [], $now, $now));
        $this->storage->delete('aud');
        $this->assertNull($this->storage->findByName('aud'));
    }
}
