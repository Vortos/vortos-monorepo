<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Auth\Quota\Attribute\RequiresQuota;
use Vortos\Auth\Quota\Contract\QuotaPolicyInterface;
use Vortos\Auth\Quota\Contract\QuotaSubjectResolverInterface;
use Vortos\Auth\Quota\Exception\InvalidQuotaSubjectResolverException;
use Vortos\Auth\Quota\Middleware\QuotaMiddleware;
use Vortos\Auth\Quota\Resolver\GlobalQuotaResolver;
use Vortos\Auth\Quota\Resolver\UserQuotaResolver;

final class QuotaCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(QuotaMiddleware::class)) return;

        $routeMap = [];
        $policyServiceIds = [];
        $resolverServiceIds = [];

        foreach ([UserQuotaResolver::class, GlobalQuotaResolver::class] as $resolverClass) {
            if (!$container->hasDefinition($resolverClass) && !$container->hasAlias($resolverClass)) {
                $container->register($resolverClass, $resolverClass)
                    ->setShared(true)
                    ->setPublic(false);
            }
        }

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) continue;
            if (is_a($class, QuotaPolicyInterface::class, true)) {
                $policyServiceIds[$class] = $serviceId;
            }
            if (is_a($class, QuotaSubjectResolverInterface::class, true)) {
                $resolverServiceIds[$class] = $serviceId;
            }
        }

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) continue;
            if (!$definition->hasTag('vortos.api.controller') &&
                !$definition->hasTag('controller.service_arguments')) continue;

            $reflection = new \ReflectionClass($class);

            // Class-level attributes
            foreach ($reflection->getAttributes(RequiresQuota::class) as $attr) {
                $instance = $attr->newInstance();
                $this->validateResolver($instance->by, $resolverServiceIds);
                $this->validateCost($instance->cost);
                $routeMap[$class][] = ['quota' => $instance->quota, 'cost' => $instance->cost, 'by' => $instance->by];
            }

            // Method-level attributes
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(RequiresQuota::class) as $attr) {
                    $instance = $attr->newInstance();
                    $this->validateResolver($instance->by, $resolverServiceIds);
                    $this->validateCost($instance->cost);
                    $routeMap[$class][] = ['quota' => $instance->quota, 'cost' => $instance->cost, 'by' => $instance->by];
                }
            }
        }

        $policyRefs = [];
        foreach ($policyServiceIds as $class => $id) {
            $policyRefs[$class] = new Reference($id);
        }

        $resolverRefs = [];
        foreach ($resolverServiceIds as $class => $id) {
            $resolverRefs[$class] = new Reference($id);
        }

        $container->getDefinition(QuotaMiddleware::class)
            ->setArgument('$routeMap', $routeMap)
            ->setArgument('$policies', $policyRefs)
            ->setArgument('$resolvers', $resolverRefs);
    }

    /**
     * @param array<string, string> $resolverServiceIds
     */
    private function validateResolver(string $resolverClass, array $resolverServiceIds): void
    {
        if (!class_exists($resolverClass)) {
            throw new InvalidQuotaSubjectResolverException(sprintf('Quota subject resolver "%s" does not exist.', $resolverClass));
        }

        if (!is_a($resolverClass, QuotaSubjectResolverInterface::class, true)) {
            throw new InvalidQuotaSubjectResolverException(sprintf(
                'Quota subject resolver "%s" must implement %s.',
                $resolverClass,
                QuotaSubjectResolverInterface::class,
            ));
        }

        if (!isset($resolverServiceIds[$resolverClass])) {
            throw new InvalidQuotaSubjectResolverException(sprintf('Quota subject resolver "%s" is not registered as a service.', $resolverClass));
        }
    }

    private function validateCost(int $cost): void
    {
        if ($cost < 1) {
            throw new \InvalidArgumentException('Quota cost must be greater than zero.');
        }
    }
}
