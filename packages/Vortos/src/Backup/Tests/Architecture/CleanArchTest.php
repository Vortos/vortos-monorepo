<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The pure domain must not depend on infrastructure (DBAL, the object store, Symfony
 * DI/Console). If it does, the "pure, fully unit-testable" guarantee is broken.
 */
final class CleanArchTest extends TestCase
{
    private const FORBIDDEN = [
        'use Doctrine\\',
        'use Vortos\\ObjectStore\\',
        'use Symfony\\',
        'use Vortos\\OpsKit\\Driver\\', // domain doesn't know the driver kit
    ];

    public function test_domain_has_no_infrastructure_dependencies(): void
    {
        $domainDir = dirname(__DIR__, 2) . '/Domain';
        $files = $this->phpFiles($domainDir);
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach (self::FORBIDDEN as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $source,
                    sprintf('Domain file %s must not import infrastructure (%s).', basename($file), $needle),
                );
            }
        }
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
