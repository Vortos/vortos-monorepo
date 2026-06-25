<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * §11.3: Deploy must not depend on Observability — the audit/marker concern is
 * Observability's. They meet only at the seam Deploy declares
 * ({@see \Vortos\Deploy\Audit\DeployAuditSinkInterface}); Observability implements
 * it and Deploy is autowired with whatever sinks are registered, without ever
 * referencing `Vortos\Observability\` by name.
 */
final class DeployObservabilityDependencyDirectionTest extends TestCase
{
    public function test_deploy_package_never_references_the_observability_namespace(): void
    {
        $packageDir = dirname(__DIR__, 2);
        $violations = [];

        foreach ($this->phpFiles($packageDir) as $file) {
            $path = $file->getPathname();
            if (str_contains($path, '/Tests/')) {
                continue;
            }

            $source = (string) file_get_contents($path);
            if (preg_match('/\bVortos\\\\Observability\\\\/', $source) === 1) {
                $violations[] = str_replace($packageDir . '/', '', $path);
            }
        }

        self::assertSame(
            [],
            $violations,
            "These Deploy files reference Vortos\\Observability\\ (forbidden — Deploy must not depend on Observability):\n  - "
            . implode("\n  - ", $violations),
        );
    }

    /** @return iterable<\SplFileInfo> */
    private function phpFiles(string $dir): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }
}
