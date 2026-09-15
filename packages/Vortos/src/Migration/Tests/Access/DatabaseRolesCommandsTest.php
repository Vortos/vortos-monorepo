<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Access;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Migration\Access\DatabaseRoleConformanceInspector;
use Vortos\Migration\Access\DatabaseRoleConvergenceSql;
use Vortos\Migration\Console\DatabaseRolesCheckCommand;
use Vortos\Migration\Console\DatabaseRolesGrantCommand;
use Vortos\Migration\Console\DatabaseRolesSqlCommand;
use Vortos\Persistence\Access\DeclaredDatabaseRoles;

final class DatabaseRolesCommandsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vortos-roles-cmd-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function envFile(string $role, string $password): string
    {
        $path = $this->dir . '/' . $role . '.env';
        file_put_contents($path, sprintf("VORTOS_WRITE_DB_DSN=pgsql://%s:%s@write_db:5432/app\n", $role, $password));
        chmod($path, 0600);

        return $path;
    }

    private static function sqlCommand(?DeclaredDatabaseRoles $declared = null): CommandTester
    {
        return new CommandTester(new DatabaseRolesSqlCommand(
            new DatabaseRoleConvergenceSql(),
            RoleModelFixture::tables(),
            $declared ?? new DeclaredDatabaseRoles(RoleModelFixture::model()->toArray()),
        ));
    }

    public function test_the_superuser_tier_prints_verifiers_and_never_the_passwords(): void
    {
        $tester = self::sqlCommand();
        $exit = $tester->execute([
            '--authority' => 'superuser',
            '--credentials' => [
                'owner:' . $this->envFile('app_owner', 'owner-secret-0123456789'),
                'runtime:' . $this->envFile('app_runtime', 'runtime-secret-0123456789'),
                'backup:' . $this->envFile('app_backup', 'backup-secret-0123456789'),
            ],
        ]);

        $output = $tester->getDisplay();
        self::assertSame(0, $exit);
        self::assertSame(3, substr_count($output, "PASSWORD 'SCRAM-SHA-256\$4096:"));
        self::assertStringNotContainsString('secret-0123456789', $output);
    }

    public function test_a_partial_password_choice_is_refused(): void
    {
        $tester = self::sqlCommand();

        self::assertSame(2, $tester->execute(['--authority' => 'superuser', '--credentials' => ['runtime:' . $this->envFile('app_runtime', 'runtime-secret-0123456789')]]));
        self::assertSame(2, $tester->execute(['--authority' => 'superuser']));
        self::assertSame(2, $tester->execute(['--authority' => 'superuser', '--keep-passwords' => true, '--credentials' => ['runtime:' . $this->envFile('app_runtime', 'runtime-secret-0123456789')]]));
        self::assertSame(2, $tester->execute(['--authority' => 'superuser', '--credentials' => ['bootstrap:/nope']]));
        self::assertSame(0, $tester->execute(['--authority' => 'superuser', '--keep-passwords' => true]));
        self::assertStringNotContainsString('PASSWORD', $tester->getDisplay());
    }

    public function test_a_credential_file_for_the_wrong_role_is_refused(): void
    {
        $tester = self::sqlCommand();

        self::assertSame(2, $tester->execute([
            '--authority' => 'superuser',
            '--credentials' => [
                'owner:' . $this->envFile('app_owner', 'owner-secret-0123456789'),
                'runtime:' . $this->envFile('app_owner', 'owner-secret-0123456789'),
                'backup:' . $this->envFile('app_backup', 'backup-secret-0123456789'),
            ],
        ]));
    }

    public function test_the_owner_tier_prints_one_transaction_and_takes_no_password_options(): void
    {
        $tester = self::sqlCommand();

        self::assertSame(0, $tester->execute(['--authority' => 'owner']));
        $lines = array_values(array_filter(explode("\n", trim($tester->getDisplay()))));
        self::assertSame('BEGIN;', $lines[1]);
        self::assertSame('COMMIT;', end($lines));

        self::assertSame(2, $tester->execute(['--authority' => 'owner', '--keep-passwords' => true]));
        self::assertSame(2, $tester->execute(['--authority' => 'everyone']));
        self::assertSame(2, self::sqlCommand(new DeclaredDatabaseRoles(null))->execute(['--authority' => 'owner']));
    }

    public function test_grants_are_applied_only_by_the_owner_in_one_transaction(): void
    {
        $executed = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('app_owner');
        $connection->expects(self::once())->method('transactional')->willReturnCallback(static function (\Closure $work) use ($connection) {
            return $work($connection);
        });
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$executed): int {
            $executed[] = $sql;

            return 0;
        });

        $tester = new CommandTester(new DatabaseRolesGrantCommand($connection, new DatabaseRoleConvergenceSql(), RoleModelFixture::tables(), new DeclaredDatabaseRoles(RoleModelFixture::model()->toArray())));

        self::assertSame(0, $tester->execute([]));
        self::assertSame((new DatabaseRoleConvergenceSql())->ownerStatements(RoleModelFixture::model(), ['vortos.backup_catalog']), $executed);
    }

    public function test_grants_refuse_any_other_role(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('app_runtime');
        $connection->expects(self::never())->method('transactional');

        $tester = new CommandTester(new DatabaseRolesGrantCommand($connection, new DatabaseRoleConvergenceSql(), RoleModelFixture::tables(), new DeclaredDatabaseRoles(RoleModelFixture::model()->toArray())));

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('connected as "app_runtime"', $tester->getDisplay());
    }

    public function test_the_check_exit_code_distinguishes_violated_from_unchecked(): void
    {
        $check = static fn (array $snapshot, ?array $declaration): int => (new CommandTester(new DatabaseRolesCheckCommand(
            new DatabaseRoleConformanceInspector(RoleModelFixture::reader($snapshot), RoleModelFixture::tables(), new DeclaredDatabaseRoles($declaration)),
        )))->execute(['--json' => true]);

        $violated = RoleModelFixture::conforming();
        $violated['relations'][0]['privileges']['PUBLIC'] = ['SELECT'];

        self::assertSame(0, $check(RoleModelFixture::conforming(), RoleModelFixture::model()->toArray()));
        self::assertSame(1, $check($violated, RoleModelFixture::model()->toArray()));
        self::assertSame(2, $check([], null));
    }
}
