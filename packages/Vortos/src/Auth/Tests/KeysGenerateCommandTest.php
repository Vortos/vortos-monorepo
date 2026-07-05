<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Auth\Command\KeysGenerateCommand;

final class KeysGenerateCommandTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/vortos-keys-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            foreach (glob($this->tmp . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmp);
            @rmdir(dirname($this->tmp) . '/parent');
        }
    }

    public function test_file_mode_creates_a_missing_nested_output_dir(): void
    {
        // B14: keys:generate must mkdir -p its --out (the immutable-image path pointed provision at a
        // non-existent secrets dir and the command hard-failed).
        $nested = $this->tmp . '/secrets/jwt';
        self::assertDirectoryDoesNotExist($nested);

        $tester = new CommandTester(new KeysGenerateCommand());
        $exit = $tester->execute(['--out' => $nested, '--kid' => 'test']);

        self::assertSame(0, $exit);
        self::assertFileExists($nested . '/jwt_test_private.pem');
        self::assertFileExists($nested . '/jwt_test_public.pem');
    }

    public function test_env_mode_emits_base64_pem_env_lines(): void
    {
        // G8: the immutable-image posture wants env-content keys, not files.
        $tester = new CommandTester(new KeysGenerateCommand());
        $exit = $tester->execute(['--emit' => 'env', '--kid' => 'test']);

        self::assertSame(0, $exit);
        $out = $tester->getDisplay();

        self::assertMatchesRegularExpression('/^JWT_PRIVATE_KEY=[A-Za-z0-9+\/=]+$/m', $out);
        self::assertMatchesRegularExpression('/^JWT_PUBLIC_KEY=[A-Za-z0-9+\/=]+$/m', $out);

        // The emitted values are valid base64 PEM.
        preg_match('/^JWT_PRIVATE_KEY=(\S+)$/m', $out, $m);
        $decoded = base64_decode($m[1], true);
        self::assertIsString($decoded);
        self::assertStringContainsString('PRIVATE KEY', $decoded);
    }

    public function test_env_mode_writes_no_files(): void
    {
        $tester = new CommandTester(new KeysGenerateCommand());
        $tester->execute(['--emit' => 'env', '--out' => $this->tmp, '--kid' => 'test']);

        self::assertDirectoryDoesNotExist($this->tmp);
    }

    public function test_rejects_unknown_emit_mode(): void
    {
        $tester = new CommandTester(new KeysGenerateCommand());
        $exit = $tester->execute(['--emit' => 'bogus']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Unknown --emit mode', $tester->getDisplay());
    }
}
