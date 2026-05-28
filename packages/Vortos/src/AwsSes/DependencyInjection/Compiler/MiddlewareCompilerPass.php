<?php

declare(strict_types=1);

namespace Vortos\AwsSes\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\AwsSes\Attribute\AsEmailMiddleware;
use Vortos\AwsSes\Contract\EmailSendObserverInterface;
use Vortos\AwsSes\Middleware\EmailMiddlewareStack;
use Vortos\AwsSes\Middleware\HookMiddleware;

/**
 * Discovers middleware and observer services, sorts them, and wires them into the stack.
 *
 * Middleware discovery:
 *   Collects all services tagged 'vortos_aws_ses.email_middleware'.
 *   Priority resolution order (highest wins):
 *     1. 'priority' key in the tag attributes
 *     2. priority in #[AsEmailMiddleware(priority: N)] attribute on the class
 *     3. 0 (default)
 *   Sorted descending — highest priority runs outermost (first).
 *
 * Observer discovery:
 *   Collects all services tagged 'vortos_aws_ses.send_observer'.
 *   Injects them into HookMiddleware in tag-registration order.
 */
final class MiddlewareCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(EmailMiddlewareStack::class)) {
            return;
        }

        $this->wireMiddlewares($container);
        $this->wireObservers($container);
    }

    private function wireMiddlewares(ContainerBuilder $container): void
    {
        $tagged = $container->findTaggedServiceIds('vortos_aws_ses.email_middleware');

        $entries = [];
        foreach ($tagged as $id => $tags) {
            $priority = $tags[0]['priority'] ?? $this->readAttributePriority($container, $id);
            $entries[] = ['id' => $id, 'priority' => $priority];
        }

        usort($entries, fn($a, $b) => $b['priority'] <=> $a['priority']);

        $references = array_map(fn($e) => new Reference($e['id']), $entries);

        $container->getDefinition(EmailMiddlewareStack::class)
            ->setArgument('$middlewares', $references);
    }

    private function wireObservers(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(HookMiddleware::class)) {
            return;
        }

        $tagged = $container->findTaggedServiceIds('vortos_aws_ses.send_observer');
        $references = array_map(fn($id) => new Reference($id), array_keys($tagged));

        $container->getDefinition(HookMiddleware::class)
            ->setArgument('$observers', $references);
    }

    private function readAttributePriority(ContainerBuilder $container, string $serviceId): int
    {
        $definition = $container->getDefinition($serviceId);
        $class = $definition->getClass() ?? $serviceId;

        if (!class_exists($class)) {
            return 0;
        }

        $reflClass = new \ReflectionClass($class);
        $attrs = $reflClass->getAttributes(AsEmailMiddleware::class);

        return $attrs !== [] ? $attrs[0]->newInstance()->priority : 0;
    }
}
