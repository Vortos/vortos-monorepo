<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Secrets\DependencyInjection\Compiler\CollectKeyProvidersPass;
use Vortos\Secrets\DependencyInjection\Compiler\CollectSecretsProvidersPass;
use Vortos\Secrets\DependencyInjection\SecretsExtension;
use Vortos\Secrets\DependencyInjection\SecretsPackage;

final class SecretsPackageTest extends TestCase
{
    public function test_provides_the_secrets_extension(): void
    {
        self::assertInstanceOf(SecretsExtension::class, (new SecretsPackage())->getContainerExtension());
    }

    public function test_build_registers_both_collecting_compiler_passes(): void
    {
        $container = new ContainerBuilder();
        (new SecretsPackage())->build($container);

        $passClasses = array_map(
            static fn (object $pass): string => $pass::class,
            $container->getCompilerPassConfig()->getBeforeOptimizationPasses(),
        );

        self::assertContains(CollectSecretsProvidersPass::class, $passClasses);
        self::assertContains(CollectKeyProvidersPass::class, $passClasses);
    }
}
