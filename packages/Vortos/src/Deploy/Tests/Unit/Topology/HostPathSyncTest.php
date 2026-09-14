<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Topology;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Topology\HostPathSync;
use Vortos\Deploy\Topology\SyncedPathSpec;
use Vortos\Deploy\Topology\SyncedPathStatus;

/**
 * RC-3: host copies of bind-mounted paths converge onto the image, and nothing a root write could be
 * redirected by is followed. Runs against a real filesystem, because the defects this prevents live
 * in inodes, modes and links, not in logic a double could stand in for.
 */
final class HostPathSyncTest extends TestCase
{
    private string $root;
    private string $image;
    private string $host;
    private int $uid;
    private int $gid;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/vortos-hostpath-' . bin2hex(random_bytes(6));
        $this->image = $this->root . '/image';
        $this->host = $this->root . '/host';
        mkdir($this->image . '/docker/postgres/init', 0o755, true);
        mkdir($this->host, 0o755, true);

        file_put_contents($this->image . '/docker/postgres/init/001_create_database.sql', "select 1;\n");
        file_put_contents($this->image . '/docker/postgres/init/004_replication_hba.sh', "#!/bin/sh\necho hba\n");
        chmod($this->image . '/docker/postgres/init/004_replication_hba.sh', 0o755);
        file_put_contents($this->image . '/otel.yaml', "receivers: {}\n");

        clearstatcache();
        $this->uid = (int) fileowner($this->image . '/otel.yaml');
        $this->gid = (int) filegroup($this->image . '/otel.yaml');
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->root);
    }

    private function spec(string $path, string $mode = '0644'): SyncedPathSpec
    {
        return SyncedPathSpec::parse(sprintf('%s@%s@%d:%d@svc', $path, $mode, $this->uid, $this->gid));
    }

    private function sync(): HostPathSync
    {
        return new HostPathSync($this->image, $this->host);
    }

    public function test_installs_a_directory_with_declared_modes_and_keeps_execute_only_for_executables(): void
    {
        [$result] = $this->sync()->sync([$this->spec('docker/postgres/init')], apply: true);

        self::assertSame(SyncedPathStatus::Installed, $result->status);
        self::assertSame(['docker/postgres/init/001_create_database.sql', 'docker/postgres/init/004_replication_hba.sh'], $result->changedFiles);
        clearstatcache();
        self::assertSame(0o644, fileperms($this->host . '/docker/postgres/init/001_create_database.sql') & 0o7777);
        self::assertSame(0o755, fileperms($this->host . '/docker/postgres/init/004_replication_hba.sh') & 0o7777);
        self::assertSame(0o755, fileperms($this->host . '/docker/postgres/init') & 0o7777);
        self::assertFalse($result->needsRecreate(), 'a directory bind sees renamed entries without a recreate');
    }

    public function test_an_in_sync_path_is_not_rewritten_and_leaves_no_backup(): void
    {
        $this->sync()->sync([$this->spec('docker/postgres/init')], apply: true);
        $inode = fileinode($this->host . '/docker/postgres/init/001_create_database.sql');

        [$result] = $this->sync()->sync([$this->spec('docker/postgres/init')], apply: true);

        self::assertSame(SyncedPathStatus::InSync, $result->status);
        self::assertFalse($result->applied);
        self::assertNull($result->backupDir);
        clearstatcache();
        self::assertSame($inode, fileinode($this->host . '/docker/postgres/init/001_create_database.sql'));
    }

    /** The incident: a stale init dir. Converged exactly — an extra script is an extra thing that runs. */
    public function test_updates_changed_files_removes_stale_ones_and_backs_both_up_first(): void
    {
        $this->sync()->sync([$this->spec('docker/postgres/init')], apply: true);
        file_put_contents($this->host . '/docker/postgres/init/001_create_database.sql', "select 'stale';\n");
        file_put_contents($this->host . '/docker/postgres/init/999_leftover.sql', "drop table x;\n");

        [$result] = $this->sync()->sync([$this->spec('docker/postgres/init')], apply: true);

        self::assertSame(SyncedPathStatus::Updated, $result->status);
        self::assertSame(['docker/postgres/init/001_create_database.sql'], $result->changedFiles);
        self::assertSame(['docker/postgres/init/999_leftover.sql'], $result->removedFiles);
        self::assertSame("select 1;\n", file_get_contents($this->host . '/docker/postgres/init/001_create_database.sql'));
        self::assertFileDoesNotExist($this->host . '/docker/postgres/init/999_leftover.sql');
        self::assertNotNull($result->backupDir);
        self::assertSame("select 'stale';\n", file_get_contents($result->backupDir . '/docker/postgres/init/001_create_database.sql'));
        self::assertSame("drop table x;\n", file_get_contents($result->backupDir . '/docker/postgres/init/999_leftover.sql'));
        self::assertSame([], glob($this->host . '/docker/postgres/init/*.incoming') ?: []);
    }

    public function test_a_wrong_mode_on_the_host_is_drift_even_when_the_content_matches(): void
    {
        $this->sync()->sync([$this->spec('otel.yaml')], apply: true);
        chmod($this->host . '/otel.yaml', 0o666);

        [$result] = $this->sync()->sync([$this->spec('otel.yaml')], apply: true);

        self::assertSame(SyncedPathStatus::Updated, $result->status);
        clearstatcache();
        self::assertSame(0o644, fileperms($this->host . '/otel.yaml') & 0o7777);
    }

    /** Someone who can write into an init directory can add a script that runs. */
    public function test_a_writable_synced_directory_is_drift_and_is_tightened(): void
    {
        $this->sync()->sync([$this->spec('docker/postgres/init')], apply: true);
        chmod($this->host . '/docker/postgres/init', 0o777);

        [$result] = $this->sync()->sync([$this->spec('docker/postgres/init')], apply: true);

        self::assertSame(SyncedPathStatus::Updated, $result->status);
        self::assertSame([], $result->changedFiles);
        clearstatcache();
        self::assertSame(0o755, fileperms($this->host . '/docker/postgres/init') & 0o7777);
    }

    /** A replaced single-file bind keeps the old inode in a running container. */
    public function test_a_changed_single_file_asks_for_a_recreate(): void
    {
        $this->sync()->sync([$this->spec('otel.yaml')], apply: true);
        file_put_contents($this->image . '/otel.yaml', "receivers: { otlp: {} }\n");

        [$result] = $this->sync()->sync([$this->spec('otel.yaml')], apply: true);

        self::assertTrue($result->needsRecreate());
        self::assertTrue($result->toArray()['needs_recreate']);
    }

    public function test_a_dry_run_reports_and_writes_nothing(): void
    {
        [$init, $otel] = $this->sync()->sync([$this->spec('docker/postgres/init'), $this->spec('otel.yaml')], apply: false);

        self::assertSame(SyncedPathStatus::Installed, $init->status);
        self::assertSame(SyncedPathStatus::Installed, $otel->status);
        self::assertFalse($init->applied);
        self::assertFalse($otel->needsRecreate());
        self::assertDirectoryDoesNotExist($this->host . '/docker');
        self::assertFileDoesNotExist($this->host . '/otel.yaml');
    }

    public function test_a_link_in_the_image_is_refused(): void
    {
        symlink('/etc/passwd', $this->image . '/docker/postgres/init/005_link.sql');

        $this->expectExceptionMessageMatches('/symbolic link/');

        $this->sync()->sync([$this->spec('docker/postgres/init')], apply: true);
    }

    /** A root write through a host link could land anywhere — refused before anything is written. */
    public function test_a_link_on_the_host_is_refused_and_nothing_is_written(): void
    {
        mkdir($this->root . '/elsewhere', 0o755);
        mkdir($this->host . '/docker', 0o755);
        symlink($this->root . '/elsewhere', $this->host . '/docker/postgres');

        try {
            $this->sync()->sync([$this->spec('otel.yaml'), $this->spec('docker/postgres/init')], apply: true);
            self::fail('a host link must be refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('symbolic link', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->host . '/otel.yaml', 'planning refuses before any path is written');
        self::assertSame([], glob($this->root . '/elsewhere/*') ?: []);
    }

    public function test_a_path_missing_from_the_image_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/not in the release image/');

        $this->sync()->sync([$this->spec('observability/missing.yaml')], apply: true);
    }

    public function test_a_file_in_the_image_never_replaces_a_directory_on_the_host(): void
    {
        mkdir($this->host . '/otel.yaml', 0o755);

        $this->expectExceptionMessageMatches('/refusing to replace one with the other/');

        $this->sync()->sync([$this->spec('otel.yaml')], apply: true);
    }

    public function test_an_owner_the_process_cannot_grant_is_refused_rather_than_installed_with_another(): void
    {
        if ($this->uid === 0) {
            self::markTestSkipped('running as root, which may grant any owner');
        }

        $spec = SyncedPathSpec::parse('otel.yaml@0644@0:0@svc');

        try {
            $this->sync()->sync([$spec], apply: true);
            self::fail('an ungrantable owner must be refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('refusing to install it with other permissions', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->host . '/otel.yaml');
        self::assertFileDoesNotExist($this->host . '/otel.yaml.incoming');
    }
}
