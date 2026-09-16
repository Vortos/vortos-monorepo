<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\DR;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\DR\PostgresConfigDriftInspector;
use Vortos\Backup\DR\PostgresConfigDriftKind;
use Vortos\Backup\DR\PostgresConfigDriftStatus;
use Vortos\Backup\DR\PostgresSettingsReaderInterface;
use Vortos\Backup\DR\PostgresSettingsSnapshot;
use Vortos\Backup\Health\PostgresConfigDriftProbe;
use Vortos\Health\Probe\ProbeStatus;

/**
 * RC-5 guard: the running cluster is configured by exactly the declared file — proven both ways, including
 * the live shapes measured on production on 2026-09-14 (ALTER SYSTEM settings, command-line archive flags).
 */
final class PostgresConfigDriftInspectorTest extends TestCase
{
    private const FILE = '/etc/postgresql/postgresql.conf';

    /** @param list<array{name: string, source: string, sourcefile: string}> $settings */
    private function inspector(array $settings, bool $alterSystem = false, string $configFile = self::FILE, array $errors = [], ?string $declared = self::FILE, bool $throws = false, bool $sourcesVisible = true, array $catalog = [], bool $clusterPrivileged = true): PostgresConfigDriftInspector
    {
        $reader = new class ($settings, $alterSystem, $configFile, $errors, $throws, $sourcesVisible, $catalog, $clusterPrivileged) implements PostgresSettingsReaderInterface {
            public function __construct(private array $s, private bool $a, private string $f, private array $e, private bool $t, private bool $v, private array $c, private bool $p) {}

            public function read(): PostgresSettingsSnapshot
            {
                if ($this->t) {
                    throw new \RuntimeException('SQLSTATE[08006] could not connect to postgresql://user:secret@write_db');
                }

                return new PostgresSettingsSnapshot($this->f, $this->a, $this->s, $this->e, $this->c, $this->v, $this->p);
            }
        };

        return new PostgresConfigDriftInspector($reader, $declared);
    }

    private static function fromFile(string $name, string $file = self::FILE): array
    {
        return ['name' => $name, 'source' => 'configuration file', 'sourcefile' => $file];
    }

    public function test_a_cluster_configured_only_by_the_declared_file_is_clean(): void
    {
        $report = $this->inspector([self::fromFile('wal_level'), self::fromFile('archive_command')])->inspect();

        self::assertSame(PostgresConfigDriftStatus::Clean, $report->status);
        self::assertSame([], $report->drift);
    }

    /** The production cluster as measured before RC-5: hand-applied settings and compose -c flags. */
    public function test_the_hand_configured_production_cluster_is_drift_on_every_count(): void
    {
        $report = $this->inspector(
            [
                self::fromFile('max_connections', '/var/lib/postgresql/18/docker/postgresql.conf'),
                self::fromFile('wal_compression', '/var/lib/postgresql/18/docker/postgresql.auto.conf'),
                ['name' => 'archive_mode', 'source' => 'command line', 'sourcefile' => ''],
                ['name' => 'PGDATA_ish', 'source' => 'environment variable', 'sourcefile' => ''],
            ],
            alterSystem: true,
            configFile: '/var/lib/postgresql/18/docker/postgresql.conf',
        )->inspect();

        self::assertSame(PostgresConfigDriftStatus::Drifted, $report->status);
        self::assertSame(
            ['undeclared_config_file', 'alter_system_allowed', 'undeclared_file_setting', 'alter_system_setting', 'command_line_setting', 'environment_setting'],
            array_map(static fn ($d): string => $d->kind->value, $report->drift),
        );
    }

    public function test_catalog_settings_and_unknown_sources_are_drift_under_their_own_kind(): void
    {
        $report = $this->inspector([
            ['name' => 'statement_timeout', 'source' => 'user', 'sourcefile' => ''],
            ['name' => 'work_mem', 'source' => 'database', 'sourcefile' => ''],
            ['name' => 'lock_timeout', 'source' => 'database user', 'sourcefile' => ''],
            ['name' => 'search_path', 'source' => 'global', 'sourcefile' => ''],
        ])->inspect();

        self::assertSame(
            ['catalog_setting', 'catalog_setting', 'catalog_setting', 'unrecognised_source'],
            array_map(static fn ($d): string => $d->kind->value, $report->drift),
        );
    }

    /** pg_settings shows ALTER ROLE … SET only for the connecting role; the cluster-wide catalog rows are drift too. */
    public function test_every_role_and_database_setting_in_the_cluster_is_drift_by_name_and_scope(): void
    {
        $report = $this->inspector([self::fromFile('wal_level')], catalog: [
            ['name' => 'statement_timeout', 'scope' => 'role sqoura'],
            ['name' => 'work_mem', 'scope' => 'database sqoura'],
        ])->inspect();

        self::assertSame(PostgresConfigDriftStatus::Drifted, $report->status);
        self::assertSame(
            [['kind' => 'catalog_setting', 'setting' => 'statement_timeout', 'origin' => 'role sqoura'], ['kind' => 'catalog_setting', 'setting' => 'work_mem', 'origin' => 'database sqoura']],
            array_map(static fn ($d): array => $d->toArray(), $report->drift),
        );
    }

    /**
     * A WATCHING role (superuser or pg_read_all_settings) that still cannot read pg_file_settings sees sourcefile
     * NULL: that must never read as clean or as drift, and it must be heard — the check has been blinded.
     */
    public function test_a_watching_role_that_cannot_read_file_settings_is_unverifiable_and_pages(): void
    {
        $inspector = $this->inspector([], alterSystem: true, sourcesVisible: false, clusterPrivileged: true);

        self::assertSame(PostgresConfigDriftStatus::Unverifiable, $inspector->inspect()->status);
        self::assertSame(ProbeStatus::Fail, (new PostgresConfigDriftProbe($inspector))->check()->status);
    }

    /**
     * The least-privilege application role holds no cluster-wide settings visibility BY DESIGN, so this node was
     * never the one watching the configuration. Measured on production 2026-09-16: the first night the app stopped
     * being a superuser, every colour reported "unverifiable" and paged Critical on a database the backup sidecar
     * was reporting clean the whole time.
     */
    public function test_a_node_that_does_not_watch_the_cluster_reports_it_and_never_pages(): void
    {
        $inspector = $this->inspector([], sourcesVisible: false, clusterPrivileged: false);
        $report = $inspector->inspect();

        self::assertSame(PostgresConfigDriftStatus::NotWatchedHere, $report->status);
        self::assertSame(ProbeStatus::Warn, (new PostgresConfigDriftProbe($inspector))->check()->status);
        self::assertStringContainsString('not a cluster-watching role', (string) $report->reason);
        self::assertSame([], $report->drift);
    }

    public function test_a_file_error_is_drift(): void
    {
        $report = $this->inspector([self::fromFile('shared_buffers')], errors: [['name' => 'shared_bufers', 'sourcefile' => self::FILE, 'error' => 'unrecognized configuration parameter']])->inspect();

        self::assertSame([PostgresConfigDriftKind::ConfigFileError], array_map(static fn ($d) => $d->kind, $report->drift));
    }

    public function test_reports_names_and_sources_never_values_and_never_a_driver_message(): void
    {
        $unreadable = $this->inspector([], throws: true)->inspect();

        self::assertSame(PostgresConfigDriftStatus::Indeterminate, $unreadable->status);
        self::assertStringNotContainsString('secret', (string) json_encode($unreadable->toDetail()));
        self::assertSame(['kind', 'setting', 'origin'], array_keys($this->inspector([['name' => 'x', 'source' => 'command line', 'sourcefile' => '']])->inspect()->drift[0]->toArray()));
    }

    public function test_the_probe_pages_on_drift_and_only_warns_when_nothing_is_declared_or_readable(): void
    {
        $status = static fn (PostgresConfigDriftInspector $i): ProbeStatus => (new PostgresConfigDriftProbe($i))->check()->status;

        self::assertSame(ProbeStatus::Pass, $status($this->inspector([self::fromFile('wal_level')])));
        self::assertSame(ProbeStatus::Fail, $status($this->inspector([], alterSystem: true)));
        self::assertSame(ProbeStatus::Warn, $status($this->inspector([], declared: null)));
        self::assertSame(ProbeStatus::Warn, $status($this->inspector([], throws: true)));
    }
}
