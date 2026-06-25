<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ProviderNamesOnlyInDefinitionTest extends TestCase
{
    private const PROVIDER_NAMES = [
        'ssh-compose',
        'dockerhub',
        'github',
        'caddy',
        'grafana',
        'oracle',
        'ghcr',
        'ecr',
        'gitlab',
    ];

    private const ALLOWED_PATH_FRAGMENTS = [
        '/Tests/',
        '/Resources/',
        '/Definition/',
        '/Driver/',
    ];

    public function test_provider_names_not_in_plan_or_strategy(): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];

        $scanDirs = ['Plan', 'Strategy'];

        foreach ($scanDirs as $dirName) {
            $dir = $root . '/' . $dirName;
            if (!is_dir($dir)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            ) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getPathname();
                $skip = false;
                foreach (self::ALLOWED_PATH_FRAGMENTS as $fragment) {
                    if (str_contains($path, $fragment)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }

                $code = (string) file_get_contents($path);
                foreach (self::PROVIDER_NAMES as $name) {
                    if (preg_match('/[\'"]' . preg_quote($name, '/') . '[\'"]/', $code)) {
                        $relative = str_replace($root . '/', '', $path);
                        $violations[] = $relative . ' contains provider name "' . $name . '"';
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Provider names found outside Definition/Driver:\n  - " . implode("\n  - ", $violations),
        );
    }
}
