<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Console;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Environment\DefaultEnvironment;

/**
 * R7-6: every backup command that scopes by environment must expose `--env` and default it to the
 * single canonical label (`production`) — never a divergent literal like `prod`, which silently
 * made backup:list / retention / drill see nothing that backup:run had cataloged. Also asserts the
 * two commands the app hit as rejecting `--env` (backup:doctor, backup:verify) now accept it.
 */
final class BackupEnvLabelConsistencyTest extends TestCase
{
    public function test_canonical_label_is_production(): void
    {
        self::assertSame('production', DefaultEnvironment::NAME);
    }

    public function test_no_backup_command_defaults_env_to_a_raw_literal(): void
    {
        foreach ($this->consoleFiles() as $file) {
            $src = (string) file_get_contents($file);

            // Any addOption('env', …, 'someLiteral') is forbidden — the default must be the constant.
            $this->assertDoesNotMatchRegularExpression(
                "/->addOption\('env'[^\n]*,\s*'[^']*'\s*\)/",
                $src,
                basename($file) . " defaults --env to a raw string literal; use DefaultEnvironment::NAME.",
            );
        }
    }

    public function test_env_scoped_commands_default_to_the_constant(): void
    {
        foreach ($this->consoleFiles() as $file) {
            $src = (string) file_get_contents($file);

            if (!str_contains($src, "->addOption('env'")) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                "/->addOption\('env'[^\n]*DefaultEnvironment::NAME/",
                $src,
                basename($file) . " has an --env option that does not default to DefaultEnvironment::NAME.",
            );
        }
    }

    public function test_doctor_and_verify_accept_env(): void
    {
        foreach (['BackupDoctorCommand.php', 'BackupVerifyCommand.php'] as $name) {
            $src = (string) file_get_contents($this->consoleDir() . '/' . $name);
            $this->assertStringContainsString("->addOption('env'", $src, "{$name} must accept --env.");
        }
    }

    private function consoleDir(): string
    {
        return \dirname(__DIR__, 2) . '/Console';
    }

    /** @return list<string> */
    private function consoleFiles(): array
    {
        return array_values(glob($this->consoleDir() . '/*Command.php') ?: []);
    }
}
