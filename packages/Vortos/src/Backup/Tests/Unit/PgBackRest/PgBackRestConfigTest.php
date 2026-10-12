<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\PgBackRest;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Config\BackupConfig;
use Vortos\Backup\Config\ScheduleSetBuilder;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\PgBackRest\PgBackRestRepository;
use Vortos\Backup\Schedule\BackupScheduleType;

final class PgBackRestConfigTest extends TestCase
{
    public function testTheDeclarationAndItsSchedulesBuild(): void
    {
        $config = $this->base()
            ->pgBackRest('sqoura', 'write_db', '/opt/vortos/pgbackrest.env')
            ->schedule(fn (ScheduleSetBuilder $s) => $s
                ->pgBackRestFull('0 2 * * 0', name: 'pgbackrest-full')
                ->pgBackRestDifferential('0 2 * * 1-6', name: 'pgbackrest-diff')
                ->pgBackRestVerify('30 4 * * 0', name: 'pgbackrest-verify')
                ->pgBackRestDrill('30 1 * * *', name: 'pgbackrest-drill'));

        $types = array_map(static fn ($s) => $s->type, $config->buildSchedules());
        self::assertSame([BackupScheduleType::PgBackRestFull, BackupScheduleType::PgBackRestDifferential, BackupScheduleType::PgBackRestVerify, BackupScheduleType::PgBackRestDrill], $types);
        self::assertSame([BackupKind::PhysicalBase], array_values(array_unique(array_map(static fn ($s) => $s->kind, $config->buildSchedules()), SORT_REGULAR)));
        self::assertSame('write_db', $config->pgBackRestValue()?->databaseService);
    }

    public function testSchedulesWithoutTheRepositoryAreRefused(): void
    {
        $this->expectExceptionMessage('schedules pgBackRest but does not declare its repository');
        $this->base()->schedule(fn (ScheduleSetBuilder $s) => $s->pgBackRestFull('0 2 * * 0'))->buildSchedules();
    }

    public function testARepositoryWithoutAFullScheduleIsRefused(): void
    {
        $this->expectExceptionMessage('without a ->pgBackRestFull(...) schedule');
        $this->base()
            ->pgBackRest('sqoura', 'write_db', '/opt/vortos/pgbackrest.env')
            ->schedule(fn (ScheduleSetBuilder $s) => $s->pgBackRestDifferential('0 2 * * *'))
            ->buildSchedules();
    }

    public function testTheRepositoryRefusesValuesThatWouldReachAShellOrABind(): void
    {
        foreach ([
            ['sq oura', 'write_db', '/opt/x.env'],
            ["sqoura'; rm", 'write_db', '/opt/x.env'],
            ['sqoura', 'Write DB', '/opt/x.env'],
            ['sqoura', 'write_db', 'relative.env'],
            ['sqoura', 'write_db', '/opt/../etc/shadow'],
            ['sqoura', 'write_db', '/opt/x.env:/etc/passwd'],
        ] as [$stanza, $service, $file]) {
            try {
                new PgBackRestRepository($stanza, $service, $file);
                self::fail(sprintf('accepted %s / %s / %s', $stanza, $service, $file));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEveryPgBackRestTypeHasAConsoleVerb(): void
    {
        foreach (BackupScheduleType::cases() as $type) {
            self::assertNotSame('', $type->consoleVerb());
            self::assertSame(str_starts_with($type->value, 'pgbackrest_'), $type->isPgBackRest());
        }
    }

    private function base(): BackupConfig
    {
        return BackupConfig::create()->engine('postgres')->objectives(rpoSeconds: 300, rtoSeconds: 1800);
    }
}
