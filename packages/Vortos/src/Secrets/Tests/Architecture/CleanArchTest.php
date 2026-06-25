<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class CleanArchTest extends TestCase
{
    public function test_value_vos_depend_on_nothing_infra(): void
    {
        // SecretValue legitimately calls sodium_memzero() to scrub wiped plaintext
        // from memory, so libsodium is excluded here — see test_sodium_calls_confined_to_crypto_and_driver_namespaces.
        $this->assertDirectoryFreeOfInfraDependencies('Value', "Value VOs must not depend on infrastructure:\n  - ", excludeSodium: true);
    }

    public function test_rotation_vos_depend_on_nothing_infra(): void
    {
        $this->assertDirectoryFreeOfInfraDependencies('Rotation', "Rotation domain must not depend on infrastructure:\n  - ");
    }

    public function test_preflight_vos_depend_on_nothing_infra(): void
    {
        $this->assertDirectoryFreeOfInfraDependencies('Preflight', "Preflight domain must not depend on infrastructure:\n  - ");
    }

    public function test_port_interfaces_depend_on_nothing_infra(): void
    {
        $files = [
            dirname(__DIR__, 2) . '/Provider/SecretsProviderInterface.php',
            dirname(__DIR__, 2) . '/Key/KeyProviderInterface.php',
            dirname(__DIR__, 2) . '/Store/SecretStoreInterface.php',
        ];

        $infraPatterns = ['Doctrine\\', 'Symfony\\', 'sodium_'];

        $violations = [];

        foreach ($files as $file) {
            $this->assertFileExists($file);
            $code = (string) file_get_contents($file);
            foreach ($infraPatterns as $pattern) {
                if (str_contains($code, $pattern)) {
                    $violations[] = basename($file) . ' depends on ' . $pattern;
                }
            }
        }

        $this->assertSame([], $violations, "Port interfaces must not depend on infrastructure:\n  - " . implode("\n  - ", $violations));
    }

    public function test_sodium_calls_confined_to_crypto_and_driver_namespaces(): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];

        $allowedDirs = ['Crypto', 'Driver', 'Key', 'Value'];

        $dirsToScan = ['Value', 'Rotation', 'Preflight', 'Provider', 'Store', 'Service', 'Console'];

        foreach ($dirsToScan as $dir) {
            $path = $root . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            foreach (glob($path . '/*.php') as $file) {
                if (in_array($dir, $allowedDirs, true)) {
                    continue;
                }
                $code = (string) file_get_contents($file);
                if (str_contains($code, 'sodium_')) {
                    $violations[] = basename($file) . ' in ' . $dir . '/ calls libsodium directly';
                }
            }
        }

        $this->assertSame([], $violations, "Only Crypto/, Driver/, Key/, Value/ may call libsodium directly:\n  - " . implode("\n  - ", $violations));
    }

    private function assertDirectoryFreeOfInfraDependencies(string $relativeDir, string $messagePrefix, bool $excludeSodium = false): void
    {
        $dir = dirname(__DIR__, 2) . '/' . $relativeDir;
        $this->assertDirectoryExists($dir);

        $infraPatterns = [
            'Doctrine\\',
            'Symfony\\',
            'use Vortos\\Secrets\\Driver\\',
        ];

        if (!$excludeSodium) {
            $infraPatterns[] = 'sodium_';
        }

        $violations = [];

        foreach (glob($dir . '/*.php') as $file) {
            $code = (string) file_get_contents($file);
            foreach ($infraPatterns as $pattern) {
                if (str_contains($code, $pattern)) {
                    $violations[] = basename($file) . ' depends on ' . $pattern;
                }
            }
        }

        $this->assertSame([], $violations, $messagePrefix . implode("\n  - ", $violations));
    }
}
