<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\DR;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\DR\PgSettingsReader;

/**
 * The reader itself, which nothing covered until it shipped a blind spot to production.
 *
 * alpha-414 split "does this role watch the cluster" from "can a watching role read pg_file_settings", but the
 * reader's success path — the one a watching role takes — never passed `clusterPrivileged` to the snapshot, so it
 * fell to the constructor's `false` default. The probe then reported `not_watched_here` for exactly the roles that
 * COULD read the configuration: measured on production 2026-09-16, the backup sidecar (holding pg_read_all_settings)
 * stopped watching and config drift went unreported on every node. The inspector and command tests could not catch
 * it, because they build PostgresSettingsSnapshot by hand and never construct the reader.
 */
final class PgSettingsReaderTest extends TestCase
{
    private const FILE = '/etc/postgresql/postgresql.conf';

    private function connection(bool $clusterPrivileged, bool $fileSettingsReadable): Connection
    {
        $connection = $this->createMock(Connection::class);

        $connection->method('fetchAssociative')->willReturn([
            'cluster_privileged' => $clusterPrivileged,
            'file_settings_readable' => $fileSettingsReadable,
        ]);

        $connection->method('fetchOne')->willReturnCallback(static fn (string $sql): string => match (true) {
            str_contains($sql, 'allow_alter_system') => 'off',
            str_contains($sql, 'config_file') => self::FILE,
            default => '',
        });

        $connection->method('fetchAllAssociative')->willReturn([]);

        return $connection;
    }

    /**
     * The regression. A role that watches the cluster AND can read the sources must come back as watching —
     * this is the path that silently reported the only real watcher as "not the watcher".
     */
    public function test_a_watching_role_is_reported_as_watching(): void
    {
        $snapshot = (new PgSettingsReader($this->connection(true, true)))->read();

        self::assertTrue($snapshot->clusterPrivileged, 'a role with pg_read_all_settings watches the cluster');
        self::assertTrue($snapshot->sourcesVisible);
        self::assertSame(self::FILE, $snapshot->configFile);
    }

    /** A least-privilege application role: not a failing watcher, simply not the watcher. */
    public function test_a_role_without_cluster_visibility_is_reported_as_not_watching(): void
    {
        $snapshot = (new PgSettingsReader($this->connection(false, false)))->read();

        self::assertFalse($snapshot->clusterPrivileged);
        self::assertFalse($snapshot->sourcesVisible);
    }

    /** A watching role that cannot read pg_file_settings is a blinded check: still watching, and it must be heard. */
    public function test_a_watching_role_that_cannot_read_the_sources_stays_a_watcher(): void
    {
        $snapshot = (new PgSettingsReader($this->connection(true, false)))->read();

        self::assertTrue($snapshot->clusterPrivileged);
        self::assertFalse($snapshot->sourcesVisible);
    }
}
