<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Probe\Capability\HealthCapability;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;

final class LivenessIndependenceTest extends TestCase
{
    /**
     * @dataProvider livenessProbeClassesProvider
     */
    public function testLivenessProbeDoesNotDeclareDependencyCheck(string $class): void
    {
        /** @var HealthProbeInterface $probe */
        $probe = new $class(...$this->resolveConstructorArgs($class));

        if ($probe->kind() !== ProbeKind::Liveness) {
            self::markTestSkipped("Not a liveness probe: {$class}");
        }

        self::assertFalse(
            $probe->capabilities()->supports(HealthCapability::DependencyCheck),
            "Liveness probe {$class} must NOT declare dependency_check=true. " .
            'Liveness probes that depend on downstream services cause restart storms during outages.',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function livenessProbeClassesProvider(): iterable
    {
        $baseDir = dirname(__DIR__, 2);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            if (!preg_match('/^namespace\s+([^;]+);/m', $content, $ns)) {
                continue;
            }

            if (!preg_match('/^(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $content, $cl)) {
                continue;
            }

            $fqcn = trim($ns[1]) . '\\' . trim($cl[1]);

            if (!class_exists($fqcn)) {
                continue;
            }

            $ref = new \ReflectionClass($fqcn);

            if ($ref->isAbstract() || $ref->isInterface()) {
                continue;
            }

            if (!$ref->implementsInterface(HealthProbeInterface::class)) {
                continue;
            }

            yield $fqcn => [$fqcn];
        }
    }

    /** @return array<int, mixed> */
    private function resolveConstructorArgs(string $class): array
    {
        $ref = new \ReflectionClass($class);
        $ctor = $ref->getConstructor();

        if ($ctor === null) {
            return [];
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $args[] = null;
            } else {
                self::markTestSkipped("Cannot instantiate {$class} without DI (parameter: {$param->getName()})");
            }
        }

        return $args;
    }
}
