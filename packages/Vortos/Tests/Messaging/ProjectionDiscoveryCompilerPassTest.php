<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\Domain\Event\DomainEvent;
use Vortos\Messaging\Attribute\Header\CorrelationId;
use Vortos\Messaging\Attribute\Header\MessageId;
use Vortos\Messaging\Attribute\Header\Timestamp;
use Vortos\Messaging\DependencyInjection\Compiler\ProjectionDiscoveryCompilerPass;

final readonly class TestProjectionEvent extends DomainEvent
{
    public function __construct(string $aggregateId = 'aggregate-1')
    {
        parent::__construct($aggregateId);
    }
}

final class TestProjectionHandlerWithHeaders
{
    public function __invoke(
        TestProjectionEvent $event,
        #[MessageId] string $messageId,
        #[CorrelationId] string $correlationId,
        #[Timestamp] DateTimeImmutable $timestamp,
    ): void {}
}

final class ProjectionDiscoveryCompilerPassTest extends TestCase
{
    public function test_projection_handlers_preserve_header_injection_parameters(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('vortos.handlers', []);

        $definition = new Definition(TestProjectionHandlerWithHeaders::class);
        $definition->addTag('vortos.projection_handler', [
            'consumer' => 'orders.events',
            'handlerId' => 'orders.read-model',
            'priority' => 10,
        ]);
        $container->setDefinition('test.projection_handler', $definition);

        (new ProjectionDiscoveryCompilerPass())->process($container);

        $handlers = $container->getParameter('vortos.handlers');
        $descriptor = $handlers['orders.events'][TestProjectionEvent::class][0];

        $this->assertSame('orders.read-model', $descriptor['handlerId']);
        $this->assertTrue($descriptor['idempotent']);
        $this->assertTrue($descriptor['isProjection']);
        $this->assertSame([
            ['type' => 'event', 'eventClass' => TestProjectionEvent::class],
            ['type' => 'header', 'attribute' => MessageId::class, 'paramType' => 'string'],
            ['type' => 'header', 'attribute' => CorrelationId::class, 'paramType' => 'string'],
            ['type' => 'header', 'attribute' => Timestamp::class, 'paramType' => DateTimeImmutable::class],
        ], $descriptor['parameters']);
    }
}
