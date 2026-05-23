<?php

declare(strict_types=1);

namespace Vortos\Messaging\DependencyInjection\Compiler;

use Vortos\Messaging\Hook\Attribute\AfterConsume;
use Vortos\Messaging\Hook\Attribute\AfterDispatch;
use Vortos\Messaging\Hook\Attribute\AfterHandler;
use Vortos\Messaging\Hook\Attribute\BeforeConsume;
use Vortos\Messaging\Hook\Attribute\BeforeDispatch;
use Vortos\Messaging\Hook\Attribute\BeforeHandler;
use Vortos\Messaging\Hook\Attribute\PreSend;
use Vortos\Messaging\Hook\HandlerOutcome;
use Vortos\Messaging\Hook\HookDescriptor;
use LogicException;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Discovers all services tagged vortos.hook, reads their hook attributes,
 * builds HookDescriptor arrays grouped by hook type sorted by priority descending,
 * sets the vortos.hooks container parameter, and populates vortos.hook_locator.
 */
final class HookDiscoveryCompilerPass implements CompilerPassInterface
{
    private const ATTRIBUTE_MAP = [
        BeforeDispatch::class => HookDescriptor::BEFORE_DISPATCH,
        AfterDispatch::class  => HookDescriptor::AFTER_DISPATCH,
        PreSend::class        => HookDescriptor::PRE_SEND,
        BeforeConsume::class  => HookDescriptor::BEFORE_CONSUME,
        AfterConsume::class   => HookDescriptor::AFTER_CONSUME,
        BeforeHandler::class  => HookDescriptor::BEFORE_HANDLER,
        AfterHandler::class   => HookDescriptor::AFTER_HANDLER,
    ];

    public function process(ContainerBuilder $container): void
    {
        $taggedHooks  = $container->findTaggedServiceIds('vortos.hook');
        $hooksByType  = [];
        $hookServices = [];

        foreach ($taggedHooks as $serviceId => $tags) {
            $className = $container->getDefinition($serviceId)->getClass();
            $reflClass = new ReflectionClass($className);

            $this->validateHookClass($reflClass);

            foreach (self::ATTRIBUTE_MAP as $attributeClass => $hookType) {
                foreach ($reflClass->getAttributes($attributeClass) as $attrRefl) {
                    $attr = $attrRefl->newInstance();

                    $entry = [
                        'hookType'       => $hookType,
                        'serviceId'      => $serviceId,
                        'eventFilter'    => $attr->event ?? null,
                        'consumerFilter' => $attr->consumer ?? null,
                        'priority'       => $attr->priority,
                        'onFailureOnly'  => false,
                        'on'             => [],
                    ];

                    if ($attributeClass === AfterHandler::class) {
                        $entry['on'] = $attr->on;
                    } else {
                        $entry['onFailureOnly'] = $attr->onFailureOnly ?? false;
                    }

                    $hooksByType[$hookType][] = $entry;
                }
            }

            $hookServices[$serviceId] = new ServiceClosureArgument(new Reference($serviceId));
        }

        foreach ($hooksByType as $type => $descriptors) {
            usort($descriptors, fn($a, $b) => $b['priority'] <=> $a['priority']);
            $hooksByType[$type] = $descriptors;
        }

        $container->setParameter('vortos.hooks', $hooksByType);

        $container->getDefinition('vortos.hook_locator')
            ->setArguments([$hookServices]);
    }

    private function validateHookClass(ReflectionClass $reflClass): void
    {
        if (!$reflClass->hasMethod('__invoke')) {
            throw new LogicException(
                sprintf(
                    "Hook class '%s' is tagged as vortos.hook but has no __invoke() method.",
                    $reflClass->getName()
                )
            );
        }
    }
}
