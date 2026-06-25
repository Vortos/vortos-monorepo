<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Deploy\DependencyInjection\DeployExtension;
use Vortos\Deploy\DependencyInjection\DeployPackage;

final class DeployPackageTest extends TestCase
{
    public function test_returns_deploy_extension(): void
    {
        $package = new DeployPackage();
        self::assertInstanceOf(DeployExtension::class, $package->getContainerExtension());
    }

    public function test_build_registers_compiler_passes(): void
    {
        $container = new ContainerBuilder();
        $package = new DeployPackage();

        $passesBefore = count($container->getCompilerPassConfig()->getBeforeOptimizationPasses());
        $package->build($container);
        $passesAfter = count($container->getCompilerPassConfig()->getBeforeOptimizationPasses());

        self::assertSame($passesBefore + 11, $passesAfter, 'Should register 11 compiler passes (7 driver + strategy + auth-strategy + audit-sink + canary-analyzer, Blocks 16+22+multi-registry).');
    }
}
