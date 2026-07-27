<?php

declare(strict_types=1);

namespace Vortos\Authorization\Tests\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Authorization\Command\AuthSeedCommand;
use Vortos\Authorization\Resolver\RoleGenerationStore;
use Vortos\Authorization\Contract\PermissionRegistryInterface;
use Vortos\Authorization\Permission\PermissionMetadata;

final class AuthSeedCommandTest extends TestCase
{
    private const TABLE = 'role_permissions';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE ' . self::TABLE . ' (role VARCHAR(150) NOT NULL, permission VARCHAR(190) NOT NULL, PRIMARY KEY (role, permission))',
        );
    }

    public function test_seed_inserts_default_grants_and_is_idempotent(): void
    {
        $registry = new FakeRegistry(
            all: ['payments.view', 'payments.refund'],
            defaultGrants: ['ROLE_ADMIN' => ['payments.view', 'payments.refund']],
        );
        $tester = $this->tester($registry);

        $tester->execute([]);
        $this->assertSame(2, $this->grantCount());

        // Re-running seeds nothing new (ON CONFLICT DO NOTHING).
        $tester->execute([]);
        $this->assertSame(2, $this->grantCount());
        $this->assertStringContainsString('Seeded 0 new', $tester->getDisplay());
    }

    public function test_prune_removes_grants_for_permissions_absent_from_registry(): void
    {
        // A dead permission grant that no catalog references anymore.
        $this->connection->insert(self::TABLE, ['role' => 'ROLE_ADMIN', 'permission' => 'payments.legacy_export']);
        // A non-default but still-live permission granted at runtime — must survive prune.
        $this->connection->insert(self::TABLE, ['role' => 'ROLE_SUPPORT', 'permission' => 'payments.view']);

        $registry = new FakeRegistry(
            all: ['payments.view', 'payments.refund'],
            defaultGrants: ['ROLE_ADMIN' => ['payments.view']],
        );
        $tester = $this->tester($registry);

        $tester->execute(['--prune' => true]);

        $rows = $this->grants();
        $this->assertContains(['role' => 'ROLE_ADMIN', 'permission' => 'payments.view'], $rows, 'seeded default kept');
        $this->assertContains(['role' => 'ROLE_SUPPORT', 'permission' => 'payments.view'], $rows, 'live non-default grant kept');
        $this->assertNotContains(['role' => 'ROLE_ADMIN', 'permission' => 'payments.legacy_export'], $rows, 'dead permission pruned');
        $this->assertStringContainsString('Pruned 1 dead', $tester->getDisplay());
    }

    public function test_prune_is_skipped_when_registry_reports_no_live_permissions(): void
    {
        $this->connection->insert(self::TABLE, ['role' => 'ROLE_ADMIN', 'permission' => 'payments.view']);

        // Empty registry (misconfiguration) — must NOT wipe the table.
        $registry = new FakeRegistry(
            all: [],
            defaultGrants: ['ROLE_ADMIN' => ['payments.view']],
        );
        $tester = $this->tester($registry);

        $tester->execute(['--prune' => true]);

        $this->assertSame(1, $this->grantCount(), 'empty registry must never delete every grant');
        $this->assertStringContainsString('skipping prune', $tester->getDisplay());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->connection->insert(self::TABLE, ['role' => 'ROLE_ADMIN', 'permission' => 'payments.dead']);

        $registry = new FakeRegistry(
            all: ['payments.view'],
            defaultGrants: ['ROLE_ADMIN' => ['payments.view']],
        );
        $tester = $this->tester($registry);

        $tester->execute(['--dry-run' => true, '--prune' => true]);

        $this->assertSame(1, $this->grantCount(), 'dry-run must not insert or delete');
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Would seed 1', $display);
        $this->assertStringContainsString('Would prune 1', $display);
    }

    private function tester(PermissionRegistryInterface $registry): CommandTester
    {
        return new CommandTester(new AuthSeedCommand($registry, $this->connection, self::TABLE));
    }

    private function grantCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE);
    }

    /**
     * @return list<array{role: string, permission: string}>
     */
    private function grants(): array
    {
        /** @var list<array{role: string, permission: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT role, permission FROM ' . self::TABLE);

        return $rows;
    }

    /**
     * A seed that changed anything must bust the cached permission matrix.
     *
     * The command bulk-writes grants with DBAL, bypassing GenerationalRolePermissionStore::grant()
     * — the only path that increments a role's generation. So the table was correct while every
     * running process kept resolving the PREVIOUS matrix, and a freshly seeded permission returned
     * 403 until the cache entry expired on its own.
     *
     * deploy.yml runs `vortos:auth:seed --prune` before cutover and Redis outlives the app
     * containers, so this affected every deploy that introduced a permission.
     */
    public function test_a_seed_that_changed_grants_invalidates_the_cached_matrix(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis is required to construct RoleGenerationStore.');
        }

        $redis = $this->createMock(\Redis::class);
        // increment() drives a Lua INCR per role; one call per seeded role is the observable effect.
        $redis->expects(self::atLeastOnce())->method('eval');

        $registry = new FakeRegistry(
            all: ['entries.read.any'],
            defaultGrants: ['ROLE_ADMIN' => ['entries.read.any']],
        );

        $tester = new CommandTester(new AuthSeedCommand(
            $registry,
            $this->connection,
            self::TABLE,
            new RoleGenerationStore($redis),
        ));

        $tester->execute([]);

        self::assertStringContainsString('Invalidated the cached permission matrix', $tester->getDisplay());
    }

    public function test_a_seed_that_changed_nothing_does_not_touch_the_cache(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis is required to construct RoleGenerationStore.');
        }

        $registry = new FakeRegistry(
            all: ['entries.read.any'],
            defaultGrants: ['ROLE_ADMIN' => ['entries.read.any']],
        );

        // First run inserts; second run is a no-op, so nothing should be invalidated.
        (new CommandTester(new AuthSeedCommand($registry, $this->connection, self::TABLE)))->execute([]);

        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::never())->method('eval');

        $tester = new CommandTester(new AuthSeedCommand(
            $registry,
            $this->connection,
            self::TABLE,
            new RoleGenerationStore($redis),
        ));
        $tester->execute([]);

        self::assertStringNotContainsString('Invalidated the cached permission matrix', $tester->getDisplay());
    }

    public function test_it_says_so_when_there_is_no_cache_to_invalidate(): void
    {
        $registry = new FakeRegistry(
            all: ['entries.read.any'],
            defaultGrants: ['ROLE_ADMIN' => ['entries.read.any']],
        );

        $tester = new CommandTester(new AuthSeedCommand($registry, $this->connection, self::TABLE, null));
        $tester->execute([]);

        self::assertStringContainsString('No role generation store configured', $tester->getDisplay());
    }
}

final class FakeRegistry implements PermissionRegistryInterface
{
    /**
     * @param string[]                $all
     * @param array<string, string[]> $defaultGrants
     * @param array<string, string[]> $implications
     */
    public function __construct(
        private readonly array $all,
        private readonly array $defaultGrants,
        private readonly array $implications = [],
    ) {
    }

    public function all(): array
    {
        return $this->all;
    }

    public function exists(string $permission): bool
    {
        return in_array($permission, $this->all, true);
    }

    public function metadata(string $permission): ?PermissionMetadata
    {
        return null;
    }

    public function defaultGrants(): array
    {
        return $this->defaultGrants;
    }

    public function implications(): array
    {
        return $this->implications;
    }
}
