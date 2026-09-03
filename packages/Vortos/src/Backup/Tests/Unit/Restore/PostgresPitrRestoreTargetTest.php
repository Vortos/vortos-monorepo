<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Restore;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Backup\Driver\ObjectStore\ObjectStoreBackupStore;
use Vortos\Backup\Pitr\PitrRecoveryRecorder;
use Vortos\Backup\Pitr\PostgresWalFetcher;
use Vortos\Backup\Pitr\WalArchiveFeeder;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Restore\Capability\RestoreTargetCapability;
use Vortos\Backup\Restore\Driver\Postgres\PostgresPitrRestoreTarget;
use Vortos\Backup\Restore\RestoreRequest;
use Vortos\Backup\Tests\Support\InMemoryObjectStore;
use Vortos\Backup\Tests\Support\RecordingContainerRuntime;

/**
 * What has to be inside the container, and in what order, before its postmaster boots.
 */
final class PostgresPitrRestoreTargetTest extends TestCase
{
    private const PGDATA = '/var/lib/postgresql/18/docker';

    private function target(RecordingContainerRuntime $runtime): PostgresPitrRestoreTarget
    {
        $fetcher = new PostgresWalFetcher(
            new BackupStoreRegistry(new ServiceLocator([
                's' => fn () => new ObjectStoreBackupStore(new InMemoryObjectStore()),
            ])),
            ['s'],
            'backups',
        );

        return new PostgresPitrRestoreTarget(
            $runtime,
            // Named arguments: this list has grown twice, and positional construction silently
            // shifted the scratch directory into a new int parameter the first time it did.
            new WalArchiveFeeder(
                runtime: $runtime,
                fetcher: $fetcher,
                environment: 'production',
                maxSegments: 10,
                timeoutSeconds: 1,
                segmentBytes: 4096,
                scratchDir: sys_get_temp_dir(),
            ),
            5,
        );
    }

    private function request(): RestoreRequest
    {
        return new RestoreRequest('pgsql://u:p@drill-host:5432/app', options: [
            PostgresPitrRestoreTarget::OPTION_CONTAINER_ID => 'cid',
            PostgresPitrRestoreTarget::OPTION_CONTAINER_NAME => 'cname',
            PostgresPitrRestoreTarget::OPTION_PGDATA => self::PGDATA,
            PostgresPitrRestoreTarget::OPTION_RECORDER => new PitrRecoveryRecorder(),
        ]);
    }

    /** Runs the restore far enough to record the uploads, then lets the feeder time out. */
    private function attempt(RecordingContainerRuntime $runtime): void
    {
        try {
            $this->target($runtime)->restore(['base-tar-bytes'], $this->request());
        } catch (\Throwable) {
            // The recovery cannot complete without a real postmaster; the uploads are the subject.
        }
    }

    public function testItDeclaresPointInTimeAndNotCleanRestore(): void
    {
        $capabilities = $this->target(new RecordingContainerRuntime())->capabilities();

        self::assertTrue($capabilities->supports(RestoreTargetCapability::PointInTime));
        // A base backup IS the whole cluster; there is no "restore into an existing database" mode.
        self::assertFalse($capabilities->supports(RestoreTargetCapability::CleanRestore));
    }

    /**
     * The regression this file exists for. The official image declares PGDATA at
     * /var/lib/postgresql/18/docker but ships only /var/lib/postgresql — the rest is created by the
     * entrypoint at startup, which a point-in-time restore runs before. Without creating it here the
     * base upload fails with a bare Docker 404 that reads like a corrupt artifact.
     */
    public function testItCreatesTheDataDirectoryBeforeUploadingTheBaseIntoIt(): void
    {
        $runtime = new RecordingContainerRuntime();
        $this->attempt($runtime);

        $names = $runtime->uploadedNames();
        self::assertContains(ltrim(self::PGDATA, '/') . '/', $names, 'PGDATA must be created up front');

        $pgdataUpload = null;
        $baseUpload = null;
        foreach ($runtime->uploads as $i => $upload) {
            if ($pgdataUpload === null && str_contains($upload['bytes'], ltrim(self::PGDATA, '/') . '/')) {
                $pgdataUpload = $i;
            }
            if ($baseUpload === null && $upload['bytes'] === 'base-tar-bytes') {
                $baseUpload = $i;
            }
        }

        self::assertNotNull($baseUpload, 'the base tar must be forwarded to the container');
        self::assertLessThan($baseUpload, $pgdataUpload, 'the directory must exist before the base lands in it');
        self::assertSame(self::PGDATA, $runtime->uploads[$baseUpload]['path']);
    }

    /**
     * The base backup is already a tar. It must be streamed through byte for byte — never parsed,
     * rebuilt or buffered, which is what keeps a multi-hundred-megabyte restore bounded in memory.
     */
    public function testTheBaseTarIsForwardedUnmodified(): void
    {
        $runtime = new RecordingContainerRuntime();
        $this->attempt($runtime);

        $base = array_values(array_filter(
            $runtime->uploads,
            static fn (array $u): bool => $u['bytes'] === 'base-tar-bytes',
        ));

        self::assertCount(1, $base);
    }

    /**
     * recovery.signal is what makes PostgreSQL enter archive recovery instead of starting up
     * normally at the base backup's own instant, and postgresql.auto.conf is read last so it beats
     * the production configuration travelling inside the base tar.
     */
    public function testItInstallsTheRecoveryConfigurationIntoTheDataDirectory(): void
    {
        $runtime = new RecordingContainerRuntime();
        $this->attempt($runtime);

        $names = $runtime->uploadedNames();
        self::assertContains('recovery.signal', $names);
        self::assertContains('postgresql.auto.conf', $names);

        $conf = '';
        foreach ($runtime->uploads as $upload) {
            if (str_contains($upload['bytes'], 'restore_command')) {
                $conf = $upload['bytes'];
            }
        }

        self::assertStringContainsString(WalArchiveFeeder::SCRIPT_PATH, $conf);
        // Containment: a throwaway recovery must never write into the real WAL archive it was
        // restored from, and the production config in the base tar points archive_command at it.
        self::assertStringContainsString("archive_mode = 'off'", $conf);
        // The feeder reads recovery progress out of the server log, so the locale is pinned.
        self::assertStringContainsString("lc_messages = 'C'", $conf);
    }

    public function testItRefusesARequestThatCannotDescribeARecoveryEnvironment(): void
    {
        $this->expectExceptionMessageMatches('/requires the \'container_id\' option/');

        $this->target(new RecordingContainerRuntime())
            ->restore([], new RestoreRequest('pgsql://u:p@h:5432/d'));
    }

    /**
     * Without a recorder there is no evidence, and without evidence a base backup that replayed
     * nothing is indistinguishable from a real point-in-time recovery.
     */
    public function testItRefusesToRestoreWithoutSomewhereToRecordTheEvidence(): void
    {
        $this->expectExceptionMessageMatches('/requires a .*PitrRecoveryRecorder/');

        $this->target(new RecordingContainerRuntime())->restore([], new RestoreRequest(
            'pgsql://u:p@h:5432/d',
            options: [
                PostgresPitrRestoreTarget::OPTION_CONTAINER_ID => 'cid',
                PostgresPitrRestoreTarget::OPTION_CONTAINER_NAME => 'cname',
                PostgresPitrRestoreTarget::OPTION_PGDATA => self::PGDATA,
            ],
        ));
    }
}
