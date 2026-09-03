<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Schedule;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Schedule\BackupSchedule;
use Vortos\Backup\Schedule\BackupScheduleType;
use Vortos\Backup\Schedule\CronFragmentGenerator;

/**
 * A generated crontab that silently does something weaker than its comment claims is this system's
 * recurring failure: the emitted WAL shipper and base-backup scripts both invoked command names that
 * had never existed, and failed invisibly for weeks while looking healthy.
 */
final class DrillCronFragmentTest extends TestCase
{
    private function line(BackupKind $kind): string
    {
        $fragment = (new CronFragmentGenerator())->generate([
            new BackupSchedule('a-drill', DatabaseEngine::Postgres, $kind, 'production', '0 5 * * 0', BackupScheduleType::Drill),
        ]);

        foreach (explode("\n", $fragment) as $line) {
            if (str_contains($line, 'backup:drill')) {
                return $line;
            }
        }

        self::fail('no drill line was generated');
    }

    /**
     * Without --pitr this line runs a LOGICAL restore on the point-in-time schedule: the runner takes
     * the newest restorable artifact, which on any installation taking frequent dumps is always a
     * dump. It would report green weekly having never touched a base backup or a WAL segment.
     */
    public function testAPointInTimeDrillEmitsThePitrFlag(): void
    {
        self::assertStringContainsString('--pitr', $this->line(BackupKind::PhysicalBase));
    }

    /**
     * The other direction, and it was observed on production: without a kind, a plain `backup:drill`
     * chose a base backup that happened to be four hours newer than the latest dump and spent two
     * minutes replaying WAL — on the line generated for the fast daily logical drill.
     */
    public function testALogicalDrillNamesItsKindInsteadOfPitr(): void
    {
        $line = $this->line(BackupKind::LogicalFull);

        self::assertStringNotContainsString('--pitr', $line);
        self::assertStringContainsString('--kind=logical_full', $line);
    }

    /** Whatever the kind, the emitted line must never leave the choice to chance. */
    public function testEveryDrillLineConstrainsTheArtifactKind(): void
    {
        foreach ([BackupKind::LogicalFull, BackupKind::PhysicalBase] as $kind) {
            $line = $this->line($kind);
            self::assertTrue(
                str_contains($line, '--kind=') || str_contains($line, '--pitr'),
                "generated drill line for {$kind->value} leaves the artifact kind unconstrained: {$line}",
            );
        }
    }

    /**
     * The generated flag must be the one the command actually registers. Both are read from the
     * command class here for the same reason the generator reads them: a literal in either place is
     * how "command not found" ships to production.
     */
    public function testTheEmittedNameAndFlagAreTheOnesTheCommandRegisters(): void
    {
        $command = new \Vortos\Backup\Console\BackupDrillCommand(null);

        self::assertSame(\Vortos\Backup\Console\BackupDrillCommand::NAME, $command->getName());
        self::assertTrue(
            $command->getDefinition()->hasOption(\Vortos\Backup\Console\BackupDrillCommand::OPTION_PITR),
        );
        self::assertTrue(
            $command->getDefinition()->hasOption(\Vortos\Backup\Console\BackupDrillCommand::OPTION_KIND),
        );
    }
}
