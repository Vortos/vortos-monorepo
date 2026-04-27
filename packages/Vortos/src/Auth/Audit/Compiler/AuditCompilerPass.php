<?php
declare(strict_types=1);

namespace Vortos\Auth\Audit\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Auth\Audit\Attribute\AuditLog;
use Vortos\Auth\Audit\Contract\AuditStoreInterface;
use Vortos\Auth\Audit\Middleware\AuditMiddleware;

final class AuditCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(AuditMiddleware::class)) return;

        $routeMap = [];
        $storeServiceId = null;

        // Discover AuditStoreInterface implementation
        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) continue;
            if (is_a($class, AuditStoreInterface::class, true)) {
                $storeServiceId = $serviceId;
                break;
            }
        }

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) continue;
            if (!$definition->hasTag('vortos.api.controller') &&
                !$definition->hasTag('controller.service_arguments')) continue;

            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getAttributes(AuditLog::class) as $attr) {
                $instance = $attr->newInstance();
                $routeMap[$class][] = ['action' => $instance->action, 'include' => $instance->include];
            }
        }

        $storeRef = $storeServiceId ? new Reference($storeServiceId) : null;

        $container->getDefinition(AuditMiddleware::class)
            ->setArgument('$store', $storeRef)
            ->setArgument('$routeMap', $routeMap);
    }
}
