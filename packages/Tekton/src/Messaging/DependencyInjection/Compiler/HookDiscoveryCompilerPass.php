<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\DependencyInjection\Compiler;

use Fortizan\Tekton\Messaging\Hook\Attribute\AfterConsume;
use Fortizan\Tekton\Messaging\Hook\Attribute\AfterDispatch;
use Fortizan\Tekton\Messaging\Hook\Attribute\BeforeConsume;
use Fortizan\Tekton\Messaging\Hook\Attribute\BeforeDispatch;
use Fortizan\Tekton\Messaging\Hook\Attribute\PreSend;
use Fortizan\Tekton\Messaging\Hook\HookDescriptor;
use LogicException;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Discovers all services tagged tekton.hook, reads their hook attributes,
 * builds HookDescriptor arrays grouped by hook type sorted by priority descending,
 * sets the tekton.hooks container parameter, and populates tekton.hook_locator.
 */
final class HookDiscoveryCompilerPass implements CompilerPassInterface
{
    private const ATTRIBUTE_MAP = [
        BeforeDispatch::class => HookDescriptor::BEFORE_DISPATCH,
        AfterDispatch::class  => HookDescriptor::AFTER_DISPATCH,
        PreSend::class        => HookDescriptor::PRE_SEND,
        BeforeConsume::class  => HookDescriptor::BEFORE_CONSUME,
        AfterConsume::class   => HookDescriptor::AFTER_CONSUME,
    ];

    public function process(ContainerBuilder $container): void
    {
        $taggedHooks  = $container->findTaggedServiceIds('tekton.hook');
        $hooksByType  = [];
        $hookServices = [];

        foreach ($taggedHooks as $serviceId => $tags) {
            $className = $container->getDefinition($serviceId)->getClass();
            $reflClass = new ReflectionClass($className);

            $this->validateHookClass($reflClass);

            foreach (self::ATTRIBUTE_MAP as $attributeClass => $hookType) {
                foreach ($reflClass->getAttributes($attributeClass) as $attrRefl) {
                    $attr = $attrRefl->newInstance();

                    $hooksByType[$hookType][] = new HookDescriptor(
                        hookType: $hookType,
                        serviceId: $serviceId,
                        eventFilter: $attr->event ?? null,
                        consumerFilter: $attr->consumer ?? null,
                        priority: $attr->priority,
                        onFailureOnly: $attr->onFailureOnly ?? false,
                    );
                }
            }

            $hookServices[$serviceId] = new Reference($serviceId);
        }

        foreach ($hooksByType as $type => $descriptors) {
            usort($descriptors, fn($a, $b) => $b->priority <=> $a->priority);
            $hooksByType[$type] = $descriptors;
        }

        $container->setParameter('tekton.hooks', $hooksByType);

        $container->getDefinition('tekton.hook_locator')
            ->setArguments([$hookServices]);
    }

    private function validateHookClass(ReflectionClass $reflClass): void
    {
        if (!$reflClass->hasMethod('handle')) {
            throw new LogicException(
                sprintf(
                    "Hook class '%s' is tagged as tekton.hook but has no handle() method.",
                    $reflClass->getName()
                )
            );
        }
    }
}
