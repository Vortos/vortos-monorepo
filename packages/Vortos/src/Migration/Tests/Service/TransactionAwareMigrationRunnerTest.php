<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Service;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Metadata\MigrationPlan;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\Migrator;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;
use Vortos\Migration\Service\TransactionAwareMigrationRunner;

final class TransactionAwareMigrationRunnerTest extends TestCase
{
    public function test_all_transactional_plan_runs_as_one_batch_honoring_all_or_nothing(): void
    {
        $migrator = new RecordingMigrator();
        $plan = $this->plan([
            ['001', true],
            ['002', true],
            ['003', true],
        ]);

        $count = (new TransactionAwareMigrationRunner())->run($migrator, $plan, allOrNothing: true);

        $this->assertSame(3, $count);
        $this->assertCount(1, $migrator->calls, 'A contiguous transactional run is a single migrate() call.');
        $this->assertSame(['001', '002', '003'], $migrator->calls[0]['versions']);
        $this->assertTrue($migrator->calls[0]['allOrNothing']);
    }

    public function test_non_transactional_migration_runs_alone_with_all_or_nothing_disabled(): void
    {
        $migrator = new RecordingMigrator();
        // 001,002 transactional | 003 CONCURRENTLY | 004,005 transactional
        $plan = $this->plan([
            ['001', true],
            ['002', true],
            ['003', false],
            ['004', true],
            ['005', true],
        ]);

        $count = (new TransactionAwareMigrationRunner())->run($migrator, $plan, allOrNothing: true);

        $this->assertSame(5, $count);
        $this->assertCount(3, $migrator->calls, 'Batch A | lone non-txn | batch B → three calls.');

        // Batch A: transactional pair, atomic.
        $this->assertSame(['001', '002'], $migrator->calls[0]['versions']);
        $this->assertTrue($migrator->calls[0]['allOrNothing']);

        // The CONCURRENTLY migration: alone, never wrapped in a transaction.
        $this->assertSame(['003'], $migrator->calls[1]['versions']);
        $this->assertFalse($migrator->calls[1]['allOrNothing']);

        // Batch B: transactional pair, atomic.
        $this->assertSame(['004', '005'], $migrator->calls[2]['versions']);
        $this->assertTrue($migrator->calls[2]['allOrNothing']);
    }

    public function test_execution_order_is_preserved_across_segments(): void
    {
        $migrator = new RecordingMigrator();
        $plan = $this->plan([
            ['001', false],
            ['002', true],
            ['003', false],
        ]);

        (new TransactionAwareMigrationRunner())->run($migrator, $plan, allOrNothing: true);

        $flat = array_merge(...array_column($migrator->calls, 'versions'));
        $this->assertSame(['001', '002', '003'], $flat);
    }

    public function test_all_or_nothing_false_is_propagated_to_transactional_batch(): void
    {
        $migrator = new RecordingMigrator();
        $plan = $this->plan([['001', true], ['002', true]]);

        (new TransactionAwareMigrationRunner())->run($migrator, $plan, allOrNothing: false);

        $this->assertCount(1, $migrator->calls);
        $this->assertFalse($migrator->calls[0]['allOrNothing']);
    }

    public function test_empty_plan_runs_nothing(): void
    {
        $migrator = new RecordingMigrator();

        $count = (new TransactionAwareMigrationRunner())->run(
            $migrator,
            new MigrationPlanList([], Direction::UP),
            allOrNothing: true,
        );

        $this->assertSame(0, $count);
        $this->assertCount(0, $migrator->calls);
    }

    public function test_direction_is_taken_from_incoming_plan(): void
    {
        $migrator = new RecordingMigrator();
        $plan = new MigrationPlanList(
            [$this->item('001', true, Direction::DOWN)],
            Direction::DOWN,
        );

        (new TransactionAwareMigrationRunner())->run($migrator, $plan, allOrNothing: true);

        $this->assertSame(Direction::DOWN, $migrator->calls[0]['direction']);
    }

    /**
     * @param list<array{0: string, 1: bool}> $specs [version, isTransactional]
     */
    private function plan(array $specs, string $direction = Direction::UP): MigrationPlanList
    {
        $items = array_map(
            fn (array $spec) => $this->item($spec[0], $spec[1], $direction),
            $specs,
        );

        return new MigrationPlanList($items, $direction);
    }

    private function item(string $version, bool $transactional, string $direction): MigrationPlan
    {
        $migration = $this->createMock(AbstractMigration::class);
        $migration->method('isTransactional')->willReturn($transactional);

        return new MigrationPlan(new Version($version), $migration, $direction);
    }
}

/**
 * Captures each migrate() call so the segmenting behavior can be asserted without a DB.
 */
final class RecordingMigrator implements Migrator
{
    /** @var list<array{versions: list<string>, allOrNothing: bool, direction: string}> */
    public array $calls = [];

    public function migrate(MigrationPlanList $migrationsPlan, MigratorConfiguration $migratorConfiguration): array
    {
        $this->calls[] = [
            'versions' => array_map(
                static fn (MigrationPlan $p): string => (string) $p->getVersion(),
                $migrationsPlan->getItems(),
            ),
            'allOrNothing' => $migratorConfiguration->isAllOrNothing(),
            'direction' => $migrationsPlan->getDirection(),
        ];

        return [];
    }
}
