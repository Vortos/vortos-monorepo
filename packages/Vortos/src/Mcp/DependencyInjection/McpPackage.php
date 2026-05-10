<?php

declare(strict_types=1);

namespace Vortos\Mcp\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;

final class McpPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new McpExtension();
    }

    public function build(ContainerBuilder $container): void {}
}
