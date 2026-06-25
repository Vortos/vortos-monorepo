<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class DriverNamespaceTest extends TestCase
{
    private const PROVIDER_NAMES = [
        'docker',
        'caddy',
        'dockerhub',
        'github',
        'oracle',
        'aws',
        'nginx',
        'traefik',
    ];

    public function test_provider_names_only_in_driver_namespace(): void
    {
        $deployDir = dirname(__DIR__, 2);
        $violations = [];

        foreach ($this->phpFiles($deployDir) as $file) {
            if (str_contains($file, '/Driver/')) {
                continue;
            }

            if (str_contains($file, '/Tests/')) {
                continue;
            }

            if (str_contains($file, '/Definition/')) {
                continue;
            }

            $code = strtolower((string) file_get_contents($file));
            $basename = basename($file);

            foreach (self::PROVIDER_NAMES as $name) {
                if (str_contains($code, "'" . $name . "'") || str_contains($code, '"' . $name . '"')) {
                    $violations[] = $basename . ' references provider name "' . $name . '" outside Driver/ namespace';
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Provider names found outside Driver/ namespace:\n  - " . implode("\n  - ", $violations),
        );
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
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
