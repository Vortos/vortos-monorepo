<?php

declare(strict_types=1);

namespace Vortos\Pipeline\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;
use Vortos\Pipeline\DependencyInjection\Compiler\CollectPipelineEmittersPass;

final class PipelinePackage implements PackageInterface
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new PipelineExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        CollectDriversCompilerPass::register($container, new CollectPipelineEmittersPass());
    }
}
