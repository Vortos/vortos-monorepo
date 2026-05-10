<?php

declare(strict_types=1);

namespace Vortos\Config\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Foundation\Contract\PackageInterface;

final class ConfigPackage implements PackageInterface
{
    public function build(ContainerBuilder $container): void {}

    public function getContainerExtension(): ConfigExtension
    {
        return new ConfigExtension();
    }
}
