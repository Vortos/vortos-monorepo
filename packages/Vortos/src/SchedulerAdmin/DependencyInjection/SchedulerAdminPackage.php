<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\SchedulerAdmin\DependencyInjection\Compiler\TwigExtensionCompilerPass;

final class SchedulerAdminPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new SchedulerAdminExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TwigExtensionCompilerPass());
    }
}
