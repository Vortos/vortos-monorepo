<?php

declare(strict_types=1);

namespace Vortos\Audit\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Audit\DependencyInjection\Compiler\AuditActionProviderPass;
use Vortos\Foundation\Contract\PackageInterface;

final class AuditPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new AuditExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        // Runs late so it sees action providers registered by every other module + the app.
        $container->addCompilerPass(
            new AuditActionProviderPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -50,
        );
    }
}
