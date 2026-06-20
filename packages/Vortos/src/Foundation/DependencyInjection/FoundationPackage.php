<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\Foundation\DependencyInjection\Compiler\CompilerPassDiscoveryPass;
use Vortos\Foundation\DependencyInjection\Compiler\ConsoleCommandPass;
use Vortos\Foundation\DependencyInjection\Compiler\DefaultImplCompilerPass;
use Vortos\Foundation\DependencyInjection\Compiler\DomainServiceCompilerPass;
use Vortos\Foundation\DependencyInjection\Compiler\DoctorCheckPass;
use Vortos\Foundation\DependencyInjection\Compiler\HealthCheckPass;
use Vortos\Foundation\DependencyInjection\Compiler\ResettableServicesPass;
use Vortos\Foundation\DependencyInjection\Compiler\ServiceProviderCompilerPass;

final class FoundationPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new FoundationExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new ResettableServicesPass());
        $container->addCompilerPass(new ConsoleCommandPass());
        $container->addCompilerPass(new HealthCheckPass());
        $container->addCompilerPass(new DoctorCheckPass());
        $container->addCompilerPass(new CompilerPassDiscoveryPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 200);
        $container->addCompilerPass(new DomainServiceCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 20);
        $container->addCompilerPass(new ServiceProviderCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 15);
        $container->addCompilerPass(new DefaultImplCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 5);
    }
}
