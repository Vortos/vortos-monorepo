<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Storage;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Project;
use Vortos\FeatureFlags\Storage\DatabaseProjectStorage;

/**
 * @covers \Vortos\FeatureFlags\Storage\DatabaseProjectStorage
 */
final class DatabaseProjectStorageTest extends TestCase
{
    private const TABLE = 'feature_flag_projects';

    private \Doctrine\DBAL\Connection $connection;
    private DatabaseProjectStorage $storage;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE ' . self::TABLE . ' (
                id          VARCHAR(36)  NOT NULL,
                name        VARCHAR(191) NOT NULL,
                slug        VARCHAR(191) NOT NULL UNIQUE,
                description TEXT,
                created_at  DATETIME NOT NULL,
                updated_at  DATETIME NOT NULL,
                PRIMARY KEY (id)
            )',
        );
        $this->storage = new DatabaseProjectStorage($this->connection, self::TABLE);
    }

    public function test_find_all_empty_initially(): void
    {
        $this->assertSame([], $this->storage->findAll());
    }

    public function test_save_and_find_all(): void
    {
        $this->storage->save($this->makeProject('Mobile App', 'mobile-app', '11111111-1111-4111-8111-111111111111'));
        $this->storage->save($this->makeProject('Web App', 'web-app', '22222222-2222-4222-8222-222222222222'));

        $all = $this->storage->findAll();
        $this->assertCount(2, $all);

        $slugs = array_map(fn($p) => $p->slug, $all);
        sort($slugs);
        $this->assertSame(['mobile-app', 'web-app'], $slugs);
    }

    public function test_find_by_slug_returns_matching_project(): void
    {
        $this->storage->save($this->makeProject('Mobile App', 'mobile-app'));

        $project = $this->storage->findBySlug('mobile-app');

        $this->assertNotNull($project);
        $this->assertSame('mobile-app', $project->slug);
        $this->assertSame('Mobile App', $project->name);
    }

    public function test_find_by_slug_returns_null_when_not_found(): void
    {
        $this->assertNull($this->storage->findBySlug('nonexistent'));
    }

    public function test_find_by_id_returns_matching_project(): void
    {
        $id      = '11111111-1111-4111-8111-111111111111';
        $project = $this->makeProject('Mobile App', 'mobile-app', $id);
        $this->storage->save($project);

        $found = $this->storage->findById($id);

        $this->assertNotNull($found);
        $this->assertSame($id, $found->id);
    }

    public function test_find_by_id_returns_null_when_not_found(): void
    {
        $this->assertNull($this->storage->findById('00000000-0000-0000-0000-000000000000'));
    }

    public function test_save_is_upsert_on_slug(): void
    {
        $original = $this->makeProject('Old Name', 'my-project');
        $this->storage->save($original);

        $updated = new Project(
            id:          $original->id,
            name:        'New Name',
            slug:        'my-project',
            description: 'Updated description',
            createdAt:   $original->createdAt,
            updatedAt:   new \DateTimeImmutable(),
        );
        $this->storage->save($updated);

        $all = $this->storage->findAll();
        $this->assertCount(1, $all);
        $this->assertSame('New Name', $all[0]->name);
        $this->assertSame('Updated description', $all[0]->description);
    }

    public function test_delete_removes_project(): void
    {
        $this->storage->save($this->makeProject('Mobile App', 'mobile-app'));
        $this->storage->delete('mobile-app');

        $this->assertNull($this->storage->findBySlug('mobile-app'));
    }

    public function test_delete_nonexistent_is_no_op(): void
    {
        $this->storage->delete('nonexistent');
        $this->assertSame([], $this->storage->findAll());
    }

    public function test_hydrates_all_fields(): void
    {
        $now     = new \DateTimeImmutable('2024-06-15 10:00:00');
        $project = new Project(
            id:          '11111111-1111-4111-8111-111111111111',
            name:        'My Project',
            slug:        'my-project',
            description: 'A test project',
            createdAt:   $now,
            updatedAt:   $now,
        );
        $this->storage->save($project);

        $loaded = $this->storage->findBySlug('my-project');

        $this->assertNotNull($loaded);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $loaded->id);
        $this->assertSame('My Project', $loaded->name);
        $this->assertSame('my-project', $loaded->slug);
        $this->assertSame('A test project', $loaded->description);
    }

    public function test_find_all_sorted_by_name(): void
    {
        $this->storage->save($this->makeProject('Zebra', 'zebra', '11111111-1111-4111-8111-111111111111'));
        $this->storage->save($this->makeProject('Alpha', 'alpha', '22222222-2222-4222-8222-222222222222'));
        $this->storage->save($this->makeProject('Middle', 'middle', '33333333-3333-4333-8333-333333333333'));

        $all   = $this->storage->findAll();
        $names = array_map(fn($p) => $p->name, $all);

        $this->assertSame(['Alpha', 'Middle', 'Zebra'], $names);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function makeProject(
        string $name,
        string $slug,
        string $id = '11111111-1111-4111-8111-111111111111',
    ): Project {
        $now = new \DateTimeImmutable();
        return new Project(
            id:          $id,
            name:        $name,
            slug:        $slug,
            description: '',
            createdAt:   $now,
            updatedAt:   $now,
        );
    }
}
