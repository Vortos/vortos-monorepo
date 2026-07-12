<?php

declare(strict_types=1);

namespace Vortos\AuditAdmin\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;

final class AuditAdminPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new AuditAdminExtension();
    }

    public function build(ContainerBuilder $container): void
    {
    }
}
