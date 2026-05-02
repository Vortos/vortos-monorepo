<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\Foundation\DependencyInjection\Compiler\ConsoleCommandPass;
use Vortos\Foundation\DependencyInjection\Compiler\HealthCheckPass;

final class FoundationPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new FoundationExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new ConsoleCommandPass());
        $container->addCompilerPass(new HealthCheckPass());
    }
}
