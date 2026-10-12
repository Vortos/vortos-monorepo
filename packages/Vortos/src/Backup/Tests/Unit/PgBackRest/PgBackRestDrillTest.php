<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\PgBackRest;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\DR\RecoveryObjectives;
use Vortos\Backup\Drill\Container\ContainerHandle;
use Vortos\Backup\Drill\Container\ContainerRuntimeInterface;
use Vortos\Backup\Drill\Container\ContainerSpec;
use Vortos\Backup\Drill\DrillOutcome;
use Vortos\Backup\Drill\InvariantCheck;
use Vortos\Backup\Drill\InvariantResult;
use Vortos\Backup\Health\WalFileSizeResolverInterface;
use Vortos\Backup\PgBackRest\PgBackRestClient;
use Vortos\Backup\PgBackRest\PgBackRestDrill;
use Vortos\Backup\PgBackRest\PgBackRestRepository;
use Vortos\Backup\PgBackRest\PromotedServerProbeInterface;
use Vortos\Backup\Pitr\PitrRecoveryOutcome;
use Vortos\Backup\Tests\Support\CollectingEventSink;
use Vortos\Backup\Tests\Support\FakePgBackRestExecutor;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Backup\Tests\Support\InMemoryDrillReportStore;
use Vortos\Backup\Tests\Support\PgBackRestInfoFixture;

final class PgBackRestDrillTest extends TestCase
{
    private FakePgBackRestExecutor $executor;
    private InMemoryDrillReportStore $reports;
    private CollectingEventSink $events;
    /** @var ContainerRuntimeInterface&object{specs: list<ContainerSpec>, removed: list<string>, logs: string} */
    private ContainerRuntimeInterface $runtime;

    protected function setUp(): void
    {
        $this->executor = new FakePgBackRestExecutor();
        $this->executor->answerInfo(PgBackRestInfoFixture::production()->json());
        $this->reports = new InMemoryDrillReportStore();
        $this->events = new CollectingEventSink();
        $this->runtime = new class implements ContainerRuntimeInterface {
            /** @var list<ContainerSpec> */
            public array $specs = [];
            /** @var list<string> */
            public array $removed = [];
            public string $logs = "2026-10-12 15:19:21.435 P00   INFO: restore size = 353.8MB, file total = 2340\n"
                . "2026-10-12 15:19:21.500 P00   INFO: restore command end: completed successfully (21000ms)\n";
            public int $sweeps = 0;

            public function ensureImage(string $image): void {}
            public function run(ContainerSpec $spec): ContainerHandle { return $this->create($spec); }
            public function create(ContainerSpec $spec): ContainerHandle { $this->specs[] = $spec; return new ContainerHandle('cid', $spec->name, $spec->name); }
            public function start(ContainerHandle $handle): void {}
            public function putArchive(ContainerHandle $handle, string $path, iterable $tarChunks): void {}
            public function logsSince(ContainerHandle $handle, int $sinceUnixSeconds): string { return $this->logs; }
            public function remove(ContainerHandle $handle): void { $this->removed[] = $handle->id; }
            public function removeOrphans(string $label, ?string $exceptId = null): int { $this->sweeps++; return 0; }
        };
    }

    public function testRestoresIntoADisposableServerThatNeverHoldsTheCredentialInItsEnvironmentAndNeverArchives(): void
    {
        $report = $this->drill(endLsn: '15/79601330')->run('production');

        self::assertSame(DrillOutcome::Passed, $report->outcome, (string) $report->error);
        self::assertSame(BackupKind::PhysicalBase, $report->kind);
        self::assertSame('pgbackrest:20261012-151549F', $report->artifactId);

        $spec = $this->runtime->specs[0];
        self::assertSame($this->executor->image, $spec->image, 'the drill restores with exactly the pgBackRest and server production runs');
        self::assertSame([], $spec->env, 'the credential never travels as container environment');
        self::assertSame(['/opt/vortos/pgbackrest.env' => '/run/vortos/pgbackrest.env'], $spec->readOnlyBinds);
        self::assertSame('0:0', $spec->user);
        self::assertSame('production-net', $spec->network);
        self::assertArrayHasKey(PgBackRestDrill::ORPHAN_LABEL, $spec->labels);

        $script = $spec->command[0];
        self::assertStringContainsString('exec su postgres', $script, 'everything after reading the credential runs as the server user');
        self::assertStringContainsString('--archive-mode=off restore --type=default', $script);
        self::assertStringContainsString('-c archive_mode=off', $script, 'a promoted drill must never push a timeline into production\'s repository');

        self::assertSame(['cid'], $this->runtime->removed);
        self::assertSame(1, $this->runtime->sweeps);
        self::assertSame($report, $this->reports->latestOfKind('postgres', 'production', BackupKind::PhysicalBase));
    }

    public function testTheReplayMeasurementFeedsTheRecoveryTimeProjection(): void
    {
        // Backup stopped in ...3C4; recovery ended in ...3CB (= the archive max): 7 segments replayed.
        $report = $this->drill(endLsn: '15/79601330')->run('production');
        $replay = array_values(array_filter($report->invariants, static fn (InvariantResult $r): bool => $r->name === 'wal_replayed'))[0];

        self::assertTrue($replay->passed, $replay->detail);
        self::assertSame(7, PitrRecoveryOutcome::segmentsFromSummary($replay->detail));
        self::assertNotNull(PitrRecoveryOutcome::recoveryMsFromSummary($replay->detail));
        self::assertStringContainsString('reached end of archive', $replay->detail);
    }

    public function testRecoveryThatStopsShortOfTheArchiveFails(): void
    {
        $report = $this->drill(endLsn: '15/79001330')->run('production');

        self::assertSame(DrillOutcome::Failed, $report->outcome);
        self::assertStringContainsString('before the newest archived segment', implode(' ', array_map(static fn (InvariantResult $r): string => $r->detail, $report->invariants)));
    }

    public function testAFailedRestoreFailsTheDrillWithPgBackRestsWordsAndStillRemovesTheContainer(): void
    {
        $this->runtime->logs .= "P00  ERROR: [082]: WAL segment 0000000100000015000003C5 was not archived before the 60000ms timeout\n";

        $report = $this->drill(endLsn: null)->run('production');

        self::assertSame(DrillOutcome::Failed, $report->outcome);
        self::assertStringContainsString('ERROR: [082]', (string) $report->error);
        self::assertSame(['cid'], $this->runtime->removed);
        self::assertCount(1, $this->events->events);
    }

    public function testStartupNoiseIsNotAFailure(): void
    {
        $this->runtime->logs .= "FATAL:  the database system is starting up\n";

        self::assertSame(DrillOutcome::Passed, $this->drill(endLsn: '15/79601330', pollsBeforePromotion: 2)->run('production')->outcome);
    }

    public function testAnUnhealthyRepositoryIsNotDrilled(): void
    {
        $this->executor->answerInfo(PgBackRestInfoFixture::production()->withStatus(2, 'no valid backups')->json());

        $report = $this->drill(endLsn: '15/79601330')->run('production');

        self::assertSame(DrillOutcome::Failed, $report->outcome);
        self::assertStringContainsString('no valid backups', (string) $report->error);
        self::assertSame([], $this->runtime->specs);
    }

    public function testTheProjectInvariantsRunAgainstTheRestoredServer(): void
    {
        $seen = [];
        $check = new class ($seen) implements InvariantCheck {
            /** @param list<array<string, mixed>> $seen */
            public function __construct(private array &$seen) {}
            public function name(): string { return 'row_count'; }
            public function check(array $connectionParams): InvariantResult { $this->seen[] = $connectionParams; return InvariantResult::fail('row_count', 'empty'); }
        };

        $report = $this->drill(endLsn: '15/79601330', checks: [$check])->run('production');

        self::assertSame(DrillOutcome::Failed, $report->outcome);
        self::assertSame('vortos-pgbackrest-drill', substr((string) $seen[0]['host'], 0, 23));
        self::assertSame('sqoura_backup', $seen[0]['user']);
    }

    /**
     * @param list<InvariantCheck> $checks
     */
    private function drill(?string $endLsn, int $pollsBeforePromotion = 0, array $checks = []): PgBackRestDrill
    {
        $probe = new class ($endLsn, $pollsBeforePromotion) implements PromotedServerProbeInterface {
            public function __construct(private ?string $lsn, private int $wait) {}
            public function promotedReplayLsn(array $connection): ?string { return $this->wait-- > 0 ? null : $this->lsn; }
        };
        $repository = new PgBackRestRepository('sqoura', 'write_db', '/opt/vortos/pgbackrest.env');

        return new PgBackRestDrill(
            new PgBackRestClient($this->executor, $repository),
            $this->runtime,
            $probe,
            DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => 'write_db', 'user' => 'sqoura_backup', 'password' => 'x', 'dbname' => 'sqoura']),
            new class implements WalFileSizeResolverInterface {
                public function segmentBytes(): int { return 2 * 1024 * 1024; }
            },
            $this->reports,
            $this->events,
            new FixedClock(new DateTimeImmutable('2026-10-13 01:00:00')),
            new RecoveryObjectives(300, 1800),
            $checks,
            'production-net',
            timeoutSeconds: 60,
            pollMicroseconds: 0,
        );
    }
}
