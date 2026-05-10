<?php

declare(strict_types=1);

namespace Vortos\Security\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Security\Signing\Attribute\RequiresSignature;
use Vortos\Security\Signing\Middleware\RequestSignatureMiddleware;

/**
 * Scans controllers for #[RequiresSignature] at compile time.
 * Builds routeMap injected into RequestSignatureMiddleware. Zero reflection at runtime.
 */
final class RequestSignatureCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(RequestSignatureMiddleware::class)) {
            return;
        }

        $routeMap = [];

        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) {
                continue;
            }
            if (!$definition->hasTag('vortos.api.controller') &&
                !$definition->hasTag('controller.service_arguments')) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(RequiresSignature::class) as $attr) {
                    $instance = $attr->newInstance();
                    $routeMap[$class . '::' . $method->getName()] = [
                        'secret'               => $instance->secret,
                        'header'               => $instance->header,
                        'timestamp_header'     => $instance->timestampHeader,
                        'replay_window_seconds' => $instance->replayWindowSeconds,
                        'algorithm'            => $instance->algorithm,
                    ];
                }
            }

            foreach ($reflection->getAttributes(RequiresSignature::class) as $attr) {
                $instance = $attr->newInstance();
                $routeMap[$class] = [
                    'secret'               => $instance->secret,
                    'header'               => $instance->header,
                    'timestamp_header'     => $instance->timestampHeader,
                    'replay_window_seconds' => $instance->replayWindowSeconds,
                    'algorithm'            => $instance->algorithm,
                ];
            }
        }

        $container->getDefinition(RequestSignatureMiddleware::class)
            ->setArgument('$routeMap', $routeMap);
    }
}
