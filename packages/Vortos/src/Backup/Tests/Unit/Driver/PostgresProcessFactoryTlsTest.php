<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Driver;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Driver\Postgres\PostgresProcessFactory;
use Vortos\Foundation\Process\ProcessLauncherInterface;
use Vortos\Foundation\Process\ProcessResult;
use Vortos\Foundation\Process\ProcessSpec;
use Vortos\Foundation\Process\RunningProcess;
use Vortos\Foundation\Process\StreamMode;
use Vortos\Foundation\Secret\SecretValue;

/**
 * A server that refuses unencrypted connections is dumped over the TLS its DSN asks for.
 */
final class PostgresProcessFactoryTlsTest extends TestCase
{
    public function test_the_dsn_tls_settings_reach_pg_dump_as_libpq_variables(): void
    {
        $spec = $this->specFor('pgsql://backup:s3cret@write_db:5432/sqoura?sslmode=verify-full&sslrootcert=/etc/sqoura/write-db.crt', 'pgDump');

        self::assertSame('verify-full', $spec->env['PGSSLMODE'] ?? null);
        self::assertSame('/etc/sqoura/write-db.crt', $spec->env['PGSSLROOTCERT'] ?? null);
        self::assertContains('--host=write_db', $spec->argv);
        self::assertStringNotContainsString('s3cret', implode(' ', array_map('strval', $spec->argv)), 'the password stays in PGPASSFILE');
    }

    public function test_pg_basebackup_gets_them_too(): void
    {
        $spec = $this->specFor('pgsql://backup:x@write_db:5432/sqoura?sslmode=require', 'pgBaseBackup');

        self::assertSame('require', $spec->env['PGSSLMODE'] ?? null);
    }

    public function test_a_dsn_without_tls_settings_adds_none(): void
    {
        $spec = $this->specFor('pgsql://backup:x@write_db:5432/sqoura', 'pgDump');

        self::assertSame([], array_values(array_filter(array_keys($spec->env), static fn (string $k) => str_starts_with($k, 'PGSSL'))));
    }

    private function specFor(string $dsn, string $method): ProcessSpec
    {
        $params     = (new DsnParser(['pgsql' => 'pdo_pgsql']))->parse($dsn);
        $connection = DriverManager::getConnection($params);
        $launcher   = new class implements ProcessLauncherInterface {
            public ?ProcessSpec $spec = null;

            public function run(ProcessSpec $spec, string|SecretValue|null $stdin = null): ProcessResult
            {
                throw new \LogicException('not used');
            }

            public function start(ProcessSpec $spec, StreamMode $stdin, StreamMode $stdout, StreamMode $stderr): RunningProcess
            {
                $this->spec = $spec;
                throw new \RuntimeException('captured');
            }

            public function which(string $program): ?string
            {
                return '/usr/bin/' . $program;
            }
        };

        try {
            (new PostgresProcessFactory($connection, null, $launcher))->{$method}(false);
            self::fail('The launcher was never asked to start the child.');
        } catch (\RuntimeException $e) {
            self::assertSame('captured', $e->getMessage());
        }

        self::assertNotNull($launcher->spec);

        return $launcher->spec;
    }
}
