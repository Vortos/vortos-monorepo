<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Backup\Console\BackupPostgresConfigCommand;
use Vortos\Backup\DR\PostgresConfigDriftInspector;
use Vortos\Backup\DR\PostgresSettingsReaderInterface;
use Vortos\Backup\DR\PostgresSettingsSnapshot;

/** RC-5: the operator view of the drift check — exit code by status, names and origins only, never a value. */
final class BackupPostgresConfigCommandTest extends TestCase
{
    private const FILE = '/etc/postgresql/postgresql.conf';

    private static function tester(?PostgresSettingsSnapshot $snapshot, ?string $declared = self::FILE): CommandTester
    {
        $reader = new class ($snapshot) implements PostgresSettingsReaderInterface {
            public function __construct(private ?PostgresSettingsSnapshot $snapshot) {}

            public function read(): PostgresSettingsSnapshot
            {
                return $this->snapshot ?? throw new \RuntimeException('connection refused for postgresql://u:hunter2@db');
            }
        };

        return new CommandTester(new BackupPostgresConfigCommand(new PostgresConfigDriftInspector($reader, $declared)));
    }

    private static function snapshot(array $settings = [], bool $visible = true): PostgresSettingsSnapshot
    {
        return new PostgresSettingsSnapshot(self::FILE, false, $settings, [], [], $visible);
    }

    /** @return iterable<string, array{?PostgresSettingsSnapshot, ?string, int}> */
    public static function outcomes(): iterable
    {
        yield 'clean' => [self::snapshot([['name' => 'wal_level', 'source' => 'configuration file', 'sourcefile' => self::FILE]]), self::FILE, 0];
        yield 'drifted' => [self::snapshot([['name' => 'archive_mode', 'source' => 'command line', 'sourcefile' => '']]), self::FILE, 1];
        yield 'unverifiable' => [self::snapshot(visible: false), self::FILE, 1];
        yield 'undeclared' => [self::snapshot(), null, 2];
        yield 'unreadable' => [null, self::FILE, 2];
    }

    #[DataProvider('outcomes')]
    public function test_the_exit_code_follows_the_status(?PostgresSettingsSnapshot $snapshot, ?string $declared, int $exit): void
    {
        $tester = self::tester($snapshot, $declared);

        self::assertSame($exit, $tester->execute([]));
        self::assertStringNotContainsString('hunter2', $tester->getDisplay());
    }

    public function test_it_names_each_departure_and_its_origin(): void
    {
        $tester = self::tester(self::snapshot([['name' => 'wal_compression', 'source' => 'configuration file', 'sourcefile' => '/var/lib/postgresql/18/docker/postgresql.auto.conf']]));

        $tester->execute(['--json' => true]);

        $detail = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('drifted', $detail['status']);
        self::assertSame([['kind' => 'alter_system_setting', 'setting' => 'wal_compression', 'origin' => '/var/lib/postgresql/18/docker/postgresql.auto.conf']], $detail['drift']);
    }
}
