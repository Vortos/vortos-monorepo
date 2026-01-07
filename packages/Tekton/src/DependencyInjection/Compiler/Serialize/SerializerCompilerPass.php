<?php

namespace Fortizan\Tekton\DependencyInjection\Compiler\Serialize;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class SerializerCompilerPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('tekton.serializer.standard')) {
            return;
        }

        $serializerDefinition = $container->getDefinition('tekton.serializer.standard');

        $normalizers = $this->findAndSortTaggedServices('serializer.normalizer', $container);
        $encoders = $this->findAndSortTaggedServices('serializer.encoder', $container);

        $serializerDefinition->setArguments(
            [
                $normalizers,
                $encoders
            ]
        );
    }
}
