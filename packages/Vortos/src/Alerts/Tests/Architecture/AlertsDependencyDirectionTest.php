<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * `vortos-alerts` never references Deploy/Backup/Health/SES classes except inside
 * class-existence-guarded `Integration/` adapters (and `Notifier/Driver/Ses/`, the
 * thin SES adapter); upstream packages never reference `Vortos\Alerts\`.
 */
final class AlertsDependencyDirectionTest extends TestCase
{
    private const UPSTREAM_NAMESPACES = [
        'Vortos\\Deploy\\',
        'Vortos\\Backup\\',
        'Vortos\\Health\\',
        'Vortos\\AwsSes\\',
    ];

    private const ALLOWED_FRAGMENTS = ['/Integration/', '/Notifier/Driver/Ses/', '/Preflight/', '/DependencyInjection/'];

    public function test_upstream_namespaces_only_referenced_inside_guarded_integration_adapters(): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!str_ends_with((string) $file, '.php') || str_contains((string) $file, '/Tests/')) {
                continue;
            }

            $path = (string) $file;
            $isAllowed = false;
            foreach (self::ALLOWED_FRAGMENTS as $fragment) {
                if (str_contains($path, $fragment)) {
                    $isAllowed = true;
                    break;
                }
            }
            if ($isAllowed) {
                continue;
            }

            $contents = file_get_contents($path);
            foreach (self::UPSTREAM_NAMESPACES as $namespace) {
                if (str_contains((string) $contents, $namespace)) {
                    $violations[] = sprintf('%s references %s outside Integration/', $path, $namespace);
                }
            }
        }

        self::assertSame([], $violations);
    }

    public function test_upstream_packages_never_reference_alerts(): void
    {
        $upstreamRoots = [
            dirname(__DIR__, 4) . '/Deploy',
            dirname(__DIR__, 4) . '/Backup',
            dirname(__DIR__, 4) . '/Health',
        ];

        $violations = [];
        foreach ($upstreamRoots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!str_ends_with((string) $file, '.php')) {
                    continue;
                }
                $contents = file_get_contents((string) $file);
                if (str_contains((string) $contents, 'Vortos\\Alerts\\')) {
                    $violations[] = (string) $file;
                }
            }
        }

        self::assertSame([], $violations);
    }
}
