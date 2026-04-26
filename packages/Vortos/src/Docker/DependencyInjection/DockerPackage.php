<?php
declare(strict_types=1);

namespace Vortos\Docker\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Foundation\Contract\PackageInterface;

final class DockerPackage implements PackageInterface
{
    public function build(ContainerBuilder $container): void {}

    public function getContainerExtension(): DockerExtension
    {
        return new DockerExtension();
    }
}
