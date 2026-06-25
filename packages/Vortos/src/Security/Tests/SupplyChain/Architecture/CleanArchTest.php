<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Architecture;

use PHPUnit\Framework\TestCase;

final class CleanArchTest extends TestCase
{
    public function test_model_and_service_do_not_import_infra_or_symfony(): void
    {
        $dirs = [
            dirname(__DIR__, 3) . '/SupplyChain/Model',
            dirname(__DIR__, 3) . '/SupplyChain/Service',
        ];

        $forbidden = [
            'Symfony\\',
            'Vortos\\Security\\SupplyChain\\Driver\\',
            'Vortos\\Security\\SupplyChain\\Console\\',
            'Vortos\\Security\\SupplyChain\\DependencyInjection\\',
            'Vortos\\Security\\SupplyChain\\Integration\\',
        ];

        $violations = [];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                $relativePath = str_replace(dirname(__DIR__, 3) . '/SupplyChain/', '', $file->getPathname());

                foreach ($forbidden as $ns) {
                    if (str_contains($contents, 'use ' . $ns)) {
                        $violations[] = sprintf('%s imports %s', $relativePath, $ns);
                    }
                }
            }
        }

        self::assertSame([], $violations, "Clean arch violations:\n" . implode("\n", $violations));
    }

    public function test_attestation_bundle_has_no_mutator(): void
    {
        $file = dirname(__DIR__, 3) . '/SupplyChain/Model/Attestation/AttestationBundle.php';
        self::assertFileExists($file);

        $contents = file_get_contents($file);
        self::assertStringContainsString('final readonly class', $contents);
        self::assertStringNotContainsString('function set', $contents);
        self::assertStringNotContainsString('function update', $contents);
        self::assertStringNotContainsString('function delete', $contents);
    }
}
