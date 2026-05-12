<?php

declare(strict_types=1);

namespace Vortos\Tracing\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Cqrs\Command\CommandBus;
use Vortos\Cqrs\Query\QueryBus;
use Vortos\Http\EventListener\TracingMiddleware as HttpTracingMiddleware;
use Vortos\Messaging\Bus\EventBus;
use Vortos\Messaging\Driver\Kafka\Factory\KafkaConsumerFactory;
use Vortos\Messaging\Driver\Kafka\Factory\KafkaProducerFactory;
use Vortos\Messaging\Middleware\Core\TracingMiddleware as MessagingTracingMiddleware;
use Vortos\Messaging\Middleware\MiddlewareStack;
use Vortos\Messaging\Outbox\OutboxRelayWorker;
use Vortos\Messaging\Runtime\ConsumerRunner;

final class TracingModuleCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $disabled = $container->hasParameter('vortos.tracing.disabled_modules')
            ? $container->getParameter('vortos.tracing.disabled_modules')
            : [];

        if (in_array('http', $disabled, true)) {
            if ($container->hasDefinition(HttpTracingMiddleware::class)) {
                $container->removeDefinition(HttpTracingMiddleware::class);
            }
        }

        if (in_array('cqrs', $disabled, true)) {
            $this->setNullableArgument($container, CommandBus::class, '$tracer', null);
            $this->setNullableArgument($container, QueryBus::class, '$tracer', null);
        }

        if (in_array('messaging', $disabled, true)) {
            if ($container->hasDefinition(MessagingTracingMiddleware::class)) {
                $container->removeDefinition(MessagingTracingMiddleware::class);
            }
            $this->removeMiddlewareReference($container);

            foreach ([EventBus::class, OutboxRelayWorker::class, ConsumerRunner::class, KafkaProducerFactory::class, KafkaConsumerFactory::class] as $id) {
                $this->setNullableArgument($container, $id, '$tracer', null);
            }
        }
    }

    private function setNullableArgument(ContainerBuilder $container, string $id, string $argument, mixed $value): void
    {
        if (!$container->hasDefinition($id)) {
            return;
        }

        $definition = $container->getDefinition($id);
        $definition->setArgument($argument, $value);

        $indexes = [
            CommandBus::class => ['$tracer' => 5],
        ];

        if (isset($indexes[$id][$argument])) {
            $definition->setArgument($indexes[$id][$argument], $value);
        }
    }

    private function removeMiddlewareReference(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(MiddlewareStack::class)) {
            return;
        }

        $definition = $container->getDefinition(MiddlewareStack::class);
        $middlewares = $definition->getArgument('$middlewares');

        if (!is_array($middlewares)) {
            return;
        }

        $definition->setArgument('$middlewares', array_values(array_filter(
            $middlewares,
            static fn(mixed $middleware): bool => !$middleware instanceof Reference
                || (string) $middleware !== MessagingTracingMiddleware::class,
        )));
    }
}
