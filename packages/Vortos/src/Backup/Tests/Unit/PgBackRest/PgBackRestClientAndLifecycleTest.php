<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\PgBackRest;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\DR\RecoveryObjectives;
use Vortos\Backup\Drill\Container\ExecResult;
use Vortos\Backup\Health\WalFileSizeResolverInterface;
use Vortos\Backup\PgBackRest\PgBackRestClient;
use Vortos\Backup\PgBackRest\PgBackRestCommandFailedException;
use Vortos\Backup\PgBackRest\PgBackRestDrill;
use Vortos\Backup\PgBackRest\PgBackRestLifecycle;
use Vortos\Backup\PgBackRest\PgBackRestRepository;
use Vortos\Backup\PgBackRest\PromotedServerProbeInterface;
use Vortos\Backup\Schedule\BackupSchedule;
use Vortos\Backup\Schedule\BackupScheduleType;
use Vortos\Backup\Tests\Support\CollectingEventSink;
use Vortos\Backup\Tests\Support\FakePgBackRestExecutor;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Backup\Tests\Support\InMemoryDrillReportStore;
use Vortos\Backup\Tests\Support\PgBackRestInfoFixture;
use Vortos\Backup\Tests\Support\RecordingContainerRuntime;

final class PgBackRestClientAndLifecycleTest extends TestCase
{
    private FakePgBackRestExecutor $executor;

    protected function setUp(): void
    {
        $this->executor = new FakePgBackRestExecutor();
        $this->executor->answerInfo(PgBackRestInfoFixture::production()
            ->withBackup('20261012-151549F_20261013-020000D', 'diff', 1791892800, 1791892810, '0000000100000015000004C4')
            ->json());
    }

    public function testTheWorkerTakesTheDeclaredBackupTypeAndReportsWhatTheRepositoryHolds(): void
    {
        $outcome = $this->lifecycle()->execute($this->schedule(BackupScheduleType::PgBackRestDifferential));

        self::assertSame([['backup', '--type=diff'], ['info', '--output=json']], $this->executor->calls);
        self::assertStringContainsString('pgBackRest diff 20261012-151549F_20261013-020000D stored', $outcome->summary);
        self::assertStringContainsString('49.8 MiB added to the repository', $outcome->summary);
    }

    public function testAFirstDifferentialPromotedToAFullIsStillTheBackupAskedFor(): void
    {
        $this->executor->answerInfo(PgBackRestInfoFixture::production()->json());

        self::assertStringContainsString('pgBackRest full', $this->lifecycle()->execute($this->schedule(BackupScheduleType::PgBackRestDifferential))->summary);
    }

    public function testAFailingCommandThrowsWithPgBackRestsRedactedExplanation(): void
    {
        $this->executor->answers['backup'] = new ExecResult(56, '', "P00   INFO: backup command begin 2.59.1: --repo1-s3-key=<redacted>\nP00  ERROR: [056]: unable to find primary cluster - cannot proceed\n");

        try {
            $this->lifecycle()->execute($this->schedule(BackupScheduleType::PgBackRestFull));
            self::fail('a failed backup must throw so the worker retries and the dead-man pages');
        } catch (PgBackRestCommandFailedException $e) {
            self::assertStringContainsString('pgbackrest backup --type=full exited 56', $e->getMessage());
            self::assertStringContainsString('ERROR: [056]: unable to find primary cluster', $e->getMessage());
        }
    }

    public function testVerifyRunsTheRepositoryVerify(): void
    {
        $this->lifecycle()->execute($this->schedule(BackupScheduleType::PgBackRestVerify));

        self::assertSame([['verify']], $this->executor->calls);
    }

    public function testAFailedDrillThrowsSoTheWorkerRetriesAndPages(): void
    {
        $this->executor->answerInfo(PgBackRestInfoFixture::production()->withStatus(2, 'no valid backups')->json());

        $this->expectExceptionMessage('pgBackRest restore drill did not pass (outcome=failed: the repository reports status 2: no valid backups)');
        $this->lifecycle()->execute($this->schedule(BackupScheduleType::PgBackRestDrill));
    }

    public function testRefusesASchedulesThatIsNotPgBackRest(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->lifecycle()->execute($this->schedule(BackupScheduleType::Backup));
    }

    public function testNeverPassesASecretOnTheCommandLine(): void
    {
        $client = new PgBackRestClient($this->executor, new PgBackRestRepository('sqoura', 'write_db', '/opt/vortos/pgbackrest.env'));
        $client->backup(\Vortos\Backup\PgBackRest\PgBackRestBackupType::Full);
        $client->verify();
        $client->check();
        $client->info();

        foreach ($this->executor->calls as $arguments) {
            self::assertSame([], preg_grep('/key|secret|pass|cipher/i', $arguments) ?: [], implode(' ', $arguments));
        }
    }

    private function lifecycle(): PgBackRestLifecycle
    {
        $repository = new PgBackRestRepository('sqoura', 'write_db', '/opt/vortos/pgbackrest.env');
        $client = new PgBackRestClient($this->executor, $repository);

        return new PgBackRestLifecycle($client, new PgBackRestDrill(
            $client,
            new RecordingContainerRuntime(),
            new class implements PromotedServerProbeInterface {
                public function promotedReplayLsn(array $connection): ?string { return null; }
            },
            DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => 'db', 'user' => 'u', 'password' => 'p', 'dbname' => 'd']),
            new class implements WalFileSizeResolverInterface {
                public function segmentBytes(): int { return 2 << 20; }
            },
            new InMemoryDrillReportStore(),
            new CollectingEventSink(),
            new FixedClock(new DateTimeImmutable('2026-10-13 01:00:00')),
            new RecoveryObjectives(300, 1800),
            timeoutSeconds: 60,
            pollMicroseconds: 0,
        ));
    }

    private function schedule(BackupScheduleType $type): BackupSchedule
    {
        return new BackupSchedule('s', DatabaseEngine::Postgres, BackupKind::PhysicalBase, 'production', '0 2 * * *', $type);
    }
}
