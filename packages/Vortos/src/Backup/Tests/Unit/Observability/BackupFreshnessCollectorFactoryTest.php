<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Observability;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Config\BackupConfig;
use Vortos\Backup\Config\BackupConfigLoader;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Environment\DefaultEnvironment;

final class BackupFreshnessCollectorFactoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vortos-backup-cfg-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/config', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/config/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    private function writeConfig(string $body): void
    {
        file_put_contents($this->dir . '/config/backup.php', "<?php\n" . $body);
    }

    /**
     * The regression. The catalog is written under 'production' to match the deploy manifests while
     * APP_ENV is 'prod'; wiring the collector from the latter filtered on an environment no row has
     * ever carried, so it reported backup_present=0 and never emitted an age gauge at all.
     */
    public function test_environment_comes_from_the_backup_config_not_app_env(): void
    {
        $this->writeConfig(
            'use Vortos\Backup\Config\BackupConfig;' . "\n" .
            'return (new BackupConfig())->engine("postgres");'
        );

        $loader = new BackupConfigLoader($this->dir, 'prod');

        self::assertSame(DefaultEnvironment::NAME, $loader->environment());
        self::assertNotSame('prod', $loader->environment());
    }

    public function test_an_explicit_environment_is_honoured(): void
    {
        $this->writeConfig(
            'use Vortos\Backup\Config\BackupConfig;' . "\n" .
            'return (new BackupConfig())->engine("postgres")->environment("staging");'
        );

        self::assertSame('staging', (new BackupConfigLoader($this->dir, 'prod'))->environment());
    }

    public function test_only_the_configured_engine_is_reported(): void
    {
        $this->writeConfig(
            'use Vortos\Backup\Config\BackupConfig;' . "\n" .
            'return (new BackupConfig())->engine("postgres");'
        );

        // Hardcoding both engines invented a permanently-red mongo series on a stack with no Mongo.
        self::assertSame([DatabaseEngine::Postgres], (new BackupConfigLoader($this->dir, 'prod'))->engines());
    }

    public function test_without_a_config_both_engines_are_reported(): void
    {
        $loader = new BackupConfigLoader($this->dir, 'prod');

        self::assertSame(
            [DatabaseEngine::Postgres, DatabaseEngine::Mongo],
            $loader->engines(),
            'With nothing configured there is nothing to narrow by, and backup_present=0 is the '
            . 'honest answer to "is anything backing this up".',
        );
        self::assertSame(DefaultEnvironment::NAME, $loader->environment());
    }
}
