<?php

declare(strict_types=1);

namespace Vortos\Tests\Tracing;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Cqrs\Command\CommandBus;
use Vortos\Http\EventListener\TracingMiddleware as HttpTracingMiddleware;
use Vortos\Messaging\Middleware\Core\TracingMiddleware as MessagingTracingMiddleware;
use Vortos\Messaging\Middleware\MiddlewareStack;
use Vortos\Tracing\DependencyInjection\Compiler\TracingModuleCompilerPass;

final class TracingModuleCompilerPassTest extends TestCase
{
    public function test_removes_http_tracing_subscriber_when_http_module_disabled(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('vortos.tracing.disabled_modules', ['http']);
        $container->register(HttpTracingMiddleware::class);

        (new TracingModuleCompilerPass())->process($container);

        $this->assertFalse($container->hasDefinition(HttpTracingMiddleware::class));
    }

    public function test_removes_messaging_tracing_middleware_from_stack_when_messaging_module_disabled(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('vortos.tracing.disabled_modules', ['messaging']);
        $container->register(MessagingTracingMiddleware::class);
        $container->register(MiddlewareStack::class)
            ->setArgument('$middlewares', [
                new Reference(MessagingTracingMiddleware::class),
                new Reference('app.middleware'),
            ]);

        (new TracingModuleCompilerPass())->process($container);

        $this->assertFalse($container->hasDefinition(MessagingTracingMiddleware::class));
        $middlewares = $container->getDefinition(MiddlewareStack::class)->getArgument('$middlewares');
        $this->assertCount(1, $middlewares);
        $this->assertSame('app.middleware', (string) $middlewares[0]);
    }

    public function test_nulls_cqrs_tracer_argument_when_cqrs_module_disabled(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('vortos.tracing.disabled_modules', ['cqrs']);
        $container->register(CommandBus::class)
            ->setArguments([1, 2, 3, 4, 5, new Reference('tracer'), [], null]);

        (new TracingModuleCompilerPass())->process($container);

        $definition = $container->getDefinition(CommandBus::class);
        $this->assertNull($definition->getArgument(5));
        $this->assertNull($definition->getArgument('$tracer'));
    }
}
