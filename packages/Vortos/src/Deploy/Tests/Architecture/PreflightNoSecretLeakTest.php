<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Extends the SecretRedaction discipline to the doctor: no preflight finding,
 * report, runner outcome, or command output is ever populated from a SecretValue's
 * raw reveal. The fail-closed gate prints reasons, never credentials.
 */
final class PreflightNoSecretLeakTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function directories(): iterable
    {
        yield 'Preflight' => ['Preflight'];
        yield 'Runner' => ['Runner'];
        yield 'Console doctor/deploy/rollback' => ['Console'];
    }

    #[DataProvider('directories')]
    public function test_no_reveal_calls(string $relDir): void
    {
        $violations = [];

        foreach ($this->phpFiles($relDir) as $file) {
            $code = (string) file_get_contents($file);
            if (str_contains($code, '->reveal(')) {
                $violations[] = basename($file) . ' calls ->reveal()';
            }
        }

        $this->assertSame([], $violations, "secret reveal must never happen in {$relDir}:\n  - " . implode("\n  - ", $violations));
    }

    #[DataProvider('directories')]
    public function test_does_not_import_secret_value(string $relDir): void
    {
        $violations = [];

        foreach ($this->phpFiles($relDir) as $file) {
            $code = (string) file_get_contents($file);
            if (str_contains($code, 'Vortos\\Secrets\\Value\\SecretValue')) {
                $violations[] = basename($file) . ' imports SecretValue';
            }
        }

        $this->assertSame([], $violations, "{$relDir} must never import SecretValue:\n  - " . implode("\n  - ", $violations));
    }

    /** @return list<string> */
    private function phpFiles(string $relDir): array
    {
        $dir = dirname(__DIR__, 2) . '/' . $relDir;
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
