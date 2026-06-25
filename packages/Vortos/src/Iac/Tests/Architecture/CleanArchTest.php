<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class CleanArchTest extends TestCase
{
    public function test_lifecycle_domain_does_not_import_driver(): void
    {
        $lifecycleDir = dirname(__DIR__, 2) . '/Lifecycle';
        $violations = [];

        foreach ($this->phpFiles($lifecycleDir) as $file) {
            if (str_contains($file, '/Testing/')) {
                continue;
            }

            $contents = (string) file_get_contents($file);

            if (preg_match('/use\s+Vortos\\\\Iac\\\\Driver\\\\/', $contents)) {
                $violations[] = str_replace(dirname(__DIR__, 2) . '/', '', $file);
            }
        }

        $this->assertSame([], $violations,
            "Lifecycle domain VOs must not import from Driver\\:\n  - " . implode("\n  - ", $violations));
    }

    public function test_iac_does_not_import_deploy(): void
    {
        $iacRoot = dirname(__DIR__, 2);
        $violations = [];

        foreach ($this->phpFiles($iacRoot) as $file) {
            if (str_contains($file, '/Tests/')) {
                continue;
            }

            $contents = (string) file_get_contents($file);

            if (preg_match('/use\s+Vortos\\\\Deploy\\\\/', $contents)) {
                $violations[] = str_replace($iacRoot . '/', '', $file);
            }
        }

        $this->assertSame([], $violations,
            "IaC must not import from Deploy\\ (the doctor check lives in Deploy, not IaC):\n  - " . implode("\n  - ", $violations));
    }

    /** @return iterable<string> */
    private function phpFiles(string $dir): iterable
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
