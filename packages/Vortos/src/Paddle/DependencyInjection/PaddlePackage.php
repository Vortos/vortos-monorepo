<?php

declare(strict_types=1);

namespace Vortos\Paddle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\Paddle\DependencyInjection\Compiler\WebhookHandlerDiscoveryPass;

final class PaddlePackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new PaddleExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new WebhookHandlerDiscoveryPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 80);
    }
}
