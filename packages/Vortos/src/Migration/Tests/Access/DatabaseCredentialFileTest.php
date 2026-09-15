<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Access;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Migration\Access\DatabaseCredentialFile;
use Vortos\Persistence\Access\DatabaseRoleName;

final class DatabaseCredentialFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vortos-cred-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function file(string $contents, int $mode = 0600): string
    {
        $path = $this->dir . '/' . bin2hex(random_bytes(4)) . '.env';
        file_put_contents($path, $contents);
        chmod($path, $mode);

        return $path;
    }

    public function test_it_reads_the_password_of_the_role_the_dsn_names(): void
    {
        $path = $this->file("APP_ENV=prod\nVORTOS_WRITE_DB_DSN=pgsql://app_runtime:p%40ss%2Fword-0123456789@write_db:5432/app\n", 0640);

        self::assertSame('p@ss/word-0123456789', DatabaseCredentialFile::passwordFor(new DatabaseRoleName('app_runtime'), $path)->reveal());
    }

    public function test_quoted_values_are_unquoted(): void
    {
        $path = $this->file("VORTOS_WRITE_DB_DSN=\"postgresql://app_backup:0123456789abcdef@write_db/app\"\n");

        self::assertSame('0123456789abcdef', DatabaseCredentialFile::passwordFor(new DatabaseRoleName('app_backup'), $path)->reveal());
    }

    public function test_a_file_pointing_one_audience_at_another_role_is_refused_without_naming_the_password(): void
    {
        $path = $this->file("VORTOS_WRITE_DB_DSN=pgsql://app_runtime:do-not-print-me-0123@write_db/app\n");

        try {
            DatabaseCredentialFile::passwordFor(new DatabaseRoleName('app_backup'), $path);
            self::fail('accepted the runtime DSN as the backup credential');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('connects as "app_runtime"', $e->getMessage());
            self::assertStringNotContainsString('do-not-print-me', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function refusals(): iterable
    {
        yield 'world-readable' => ["VORTOS_WRITE_DB_DSN=pgsql://app_runtime:0123456789abcdef@h/app\n", 0644, 'readable by other users'];
        yield 'no dsn' => ["VORTOS_READ_DB_DSN=pgsql://app_runtime:0123456789abcdef@h/app\n", 0600, 'does not set'];
        yield 'no password' => ["VORTOS_WRITE_DB_DSN=pgsql://app_runtime@h/app\n", 0600, 'carries no password'];
        yield 'not postgres' => ["VORTOS_WRITE_DB_DSN=mysql://app_runtime:0123456789abcdef@h/app\n", 0600, 'not a PostgreSQL DSN'];
    }

    #[DataProvider('refusals')]
    public function test_unusable_credential_files_are_refused(string $contents, int $mode, string $message): void
    {
        $path = $this->file($contents, $mode);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        DatabaseCredentialFile::passwordFor(new DatabaseRoleName('app_runtime'), $path);
    }

    public function test_a_symlink_is_refused(): void
    {
        $target = $this->file("VORTOS_WRITE_DB_DSN=pgsql://app_runtime:0123456789abcdef@h/app\n");
        $link = $this->dir . '/link.env';
        symlink($target, $link);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a regular file');

        DatabaseCredentialFile::passwordFor(new DatabaseRoleName('app_runtime'), $link);
    }
}
