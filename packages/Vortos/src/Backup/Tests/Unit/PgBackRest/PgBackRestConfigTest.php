<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\PgBackRest;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Config\BackupConfig;
use Vortos\Backup\Config\ScheduleSetBuilder;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\PgBackRest\PgBackRestOffsite;
use Vortos\Backup\PgBackRest\PgBackRestRepository;
use Vortos\Backup\PgBackRest\PgBackRestRepositoryRole;
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

    public function testAnOffsiteCopyCarriesItsOwnSchedules(): void
    {
        $config = $this->base()
            ->pgBackRest('sqoura', 'write_db', '/opt/vortos/pgbackrest.env', offsite: self::offsite())
            ->schedule(fn (ScheduleSetBuilder $s) => $s
                ->pgBackRestFull('0 2 * * 0', name: 'pgbackrest-full')
                ->pgBackRestDrill('30 1 * * *', name: 'pgbackrest-drill')
                ->pgBackRestFull('0 3 * * 0', name: 'pgbackrest-offsite-full', offsite: true)
                ->pgBackRestDifferential('0 3 * * 1-6', name: 'pgbackrest-offsite-diff', offsite: true)
                ->pgBackRestDrill('0 5 * * 0', name: 'pgbackrest-offsite-drill', offsite: true));

        $roles = [];
        foreach ($config->buildSchedules() as $schedule) {
            $roles[$schedule->name] = $schedule->repository;
        }
        self::assertSame([
            'pgbackrest-full' => PgBackRestRepositoryRole::Primary,
            'pgbackrest-drill' => PgBackRestRepositoryRole::Primary,
            'pgbackrest-offsite-full' => PgBackRestRepositoryRole::Offsite,
            'pgbackrest-offsite-diff' => PgBackRestRepositoryRole::Offsite,
            'pgbackrest-offsite-drill' => PgBackRestRepositoryRole::Offsite,
        ], $roles);
        self::assertSame(2, $config->pgBackRestValue()?->offsite?->repo);
    }

    public function testOffsiteSchedulesWithoutAnOffsiteCopyAreRefused(): void
    {
        $this->expectExceptionMessage('schedules the off-site pgBackRest repository but does not declare it');
        $this->base()
            ->pgBackRest('sqoura', 'write_db', '/opt/vortos/pgbackrest.env')
            ->schedule(fn (ScheduleSetBuilder $s) => $s->pgBackRestFull('0 2 * * 0')->pgBackRestFull('0 3 * * 0', name: 'o', offsite: true))
            ->buildSchedules();
    }

    public function testAnOffsiteCopyThatIsNeverBackedUpOrNeverDrilledIsRefused(): void
    {
        foreach ([
            'pgBackRestFull' => fn (ScheduleSetBuilder $s) => $s->pgBackRestFull('0 2 * * 0')->pgBackRestDrill('0 5 * * 0', name: 'o', offsite: true),
            'pgBackRestDrill' => fn (ScheduleSetBuilder $s) => $s->pgBackRestFull('0 2 * * 0')->pgBackRestFull('0 3 * * 0', name: 'o', offsite: true),
        ] as $missing => $schedules) {
            try {
                $this->base()->pgBackRest('sqoura', 'write_db', '/opt/vortos/pgbackrest.env', offsite: self::offsite())->schedule($schedules)->buildSchedules();
                self::fail('accepted an off-site copy without ' . $missing);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString(sprintf('without a ->%s(..., offsite: true) schedule', $missing), $e->getMessage());
            }
        }
    }

    public function testTheOffsiteDeclarationRefusesWhatCannotBeRight(): void
    {
        foreach ([
            fn () => new PgBackRestOffsite(1, 'b2-bucket', 'https://s3.us-west-004.backblazeb2.com', 'us-west-004', 21),
            fn () => new PgBackRestOffsite(2, 'B2 Bucket', 'https://s3.us-west-004.backblazeb2.com', 'us-west-004', 21),
            fn () => new PgBackRestOffsite(2, 'b2-bucket', 'http://s3.us-west-004.backblazeb2.com', 'us-west-004', 21),
            fn () => new PgBackRestOffsite(2, 'b2-bucket', 'https://s3.us-west-004.backblazeb2.com', 'us-west-004', 0),
            fn () => new PgBackRestOffsite(2, 'b2-bucket', 'https://s3.us-west-004.backblazeb2.com', 'us-west-004', 21, 'lower_case'),
        ] as $i => $make) {
            try {
                $make();
                self::fail('accepted case ' . $i);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testASingleRepositoryKeepsItsCommandsExactlyAsTheyWere(): void
    {
        $single = new PgBackRestRepository('sqoura', 'write_db', '/opt/vortos/pgbackrest.env');
        self::assertSame([], $single->repoArgument(PgBackRestRepositoryRole::Primary));

        $this->expectException(\LogicException::class);
        $single->repoArgument(PgBackRestRepositoryRole::Offsite);
    }

    public function testEveryPgBackRestTypeHasAConsoleVerb(): void
    {
        foreach (BackupScheduleType::cases() as $type) {
            self::assertNotSame('', $type->consoleVerb());
            self::assertSame(str_starts_with($type->value, 'pgbackrest_'), $type->isPgBackRest());
        }
    }

    private static function offsite(): PgBackRestOffsite
    {
        return new PgBackRestOffsite(2, 'acme-pgbackrest-offsite', 'https://s3.us-west-004.backblazeb2.com', 'us-west-004', 21);
    }

    private function base(): BackupConfig
    {
        return BackupConfig::create()->engine('postgres')->objectives(rpoSeconds: 300, rtoSeconds: 1800);
    }
}
