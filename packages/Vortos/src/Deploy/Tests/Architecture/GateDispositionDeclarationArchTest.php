<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\OpsKit\Gate\GateDisposition;

/**
 * Pins which framework preflight checks may NOT refuse a release.
 *
 * The interface already forces every check to declare a disposition. This test makes reclassifying
 * one a visible, reviewed edit: a check silently turned Advisory would disarm a real interlock, and
 * one silently turned Blocking would reintroduce the deadlock where a gate refuses its own cure.
 */
final class GateDispositionDeclarationArchTest extends TestCase
{
    /**
     * Every framework check that reads state the release neither causes nor worsens.
     *
     * @var list<class-string>
     */
    private const ADVISORY = [
        \Vortos\Deploy\Preflight\Check\BackupReplicationAccessCheck::class,
        \Vortos\Deploy\Preflight\Check\DatabaseRolesCheck::class,
        \Vortos\Deploy\Preflight\Check\IacDriftCheck::class,
    ];

    public function test_the_advisory_checks_are_exactly_the_declared_set(): void
    {
        $advisory = [];
        $checks = $this->frameworkChecks();

        self::assertGreaterThanOrEqual(30, count($checks), 'the scan found fewer checks than exist — it is not seeing the tree');

        foreach ($checks as $class) {
            $instance = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            if ($instance->disposition() === GateDisposition::Advisory) {
                $advisory[] = $class;
            }
        }

        sort($advisory);
        $expected = self::ADVISORY;
        sort($expected);

        self::assertSame($expected, $advisory);
    }

    public function test_a_disposition_is_a_constant_declaration_not_a_runtime_decision(): void
    {
        // A disposition that depended on injected state could change between the doctor run a human
        // reads and the one the pipeline enforces. Instantiating without a constructor proves it
        // depends on nothing.
        foreach ($this->frameworkChecks() as $class) {
            $instance = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            self::assertSame($instance->disposition(), $instance->disposition(), $class);
        }
    }

    /** @return list<class-string<PreflightCheckInterface>> */
    private function frameworkChecks(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, '.php') || str_contains($path, '/Tests/')) {
                continue;
            }

            $source = (string) file_get_contents($path);
            if (!str_contains($source, 'implements PreflightCheckInterface')) {
                continue;
            }

            if (preg_match('/^namespace ([^;]+);/m', $source, $ns) !== 1
                || preg_match('/^(?:final |abstract )*(?:readonly )?class (\w+)/m', $source, $cls) !== 1) {
                continue;
            }

            $class = $ns[1] . '\\' . $cls[1];
            if (class_exists($class) && is_subclass_of($class, PreflightCheckInterface::class)) {
                $found[] = $class;
            }
        }

        sort($found);

        return $found;
    }
}
