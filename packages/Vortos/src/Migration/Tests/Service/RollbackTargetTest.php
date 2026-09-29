<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Service;

use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Version\AlphabeticalComparator;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;
use Vortos\Migration\Service\RollbackTarget;

/**
 * `vortos:migrate:rollback --steps=N` crashed on every partial rollback — the executed list is a
 * list, not a map keyed by version, and the command took its keys. These use Doctrine's real list
 * and comparator, which is exactly what the command receives.
 */
final class RollbackTargetTest extends TestCase
{
    public function test_undoing_the_last_n_rolls_back_to_the_one_before_them(): void
    {
        $executed = $this->executed('Version20260101', 'Version20260301', 'Version20260201', 'Version20260401');

        self::assertSame('Version20260201', (string) RollbackTarget::toUndo($executed, 2, new AlphabeticalComparator()));
        self::assertSame('Version20260301', (string) RollbackTarget::toUndo($executed, 1, new AlphabeticalComparator()));
    }

    public function test_undoing_as_many_as_have_run_rolls_back_to_the_start(): void
    {
        $executed = $this->executed('Version20260101', 'Version20260201');

        self::assertSame('0', (string) RollbackTarget::toUndo($executed, 2, new AlphabeticalComparator()));
        self::assertSame('0', (string) RollbackTarget::toUndo($executed, 5, new AlphabeticalComparator()));
    }

    public function test_the_order_is_the_comparators_not_the_order_they_ran_in(): void
    {
        // Executed out of order (a late-merged branch): the plan calculator still walks by version.
        $executed = $this->executed('Version20260401', 'Version20260101');

        self::assertSame('Version20260101', (string) RollbackTarget::toUndo($executed, 1, new AlphabeticalComparator()));
    }

    private function executed(string ...$versions): ExecutedMigrationsList
    {
        return new ExecutedMigrationsList(array_map(
            static fn (string $v): ExecutedMigration => new ExecutedMigration(new Version($v)),
            $versions,
        ));
    }
}
