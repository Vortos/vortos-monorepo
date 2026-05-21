<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Messaging\Attribute\AsEventHandler;
use Vortos\Messaging\Attribute\Header\CausationId;
use Vortos\Messaging\Attribute\Header\CorrelationId;
use Vortos\Messaging\Attribute\Header\Header;
use Vortos\Messaging\Attribute\Header\MessageId;
use Vortos\Messaging\Attribute\Header\TenantId;
use Vortos\Messaging\Attribute\Header\TraceId;
use Vortos\Messaging\Attribute\Header\UserId;
use Vortos\Messaging\DependencyInjection\Compiler\HandlerDiscoveryCompilerPass;

// Valid pure POPO payloads
final readonly class HandlerDiscoveryEvent
{
    public function __construct(
        public string $userId,
        public string $email,
    ) {}
}

final readonly class AnotherDiscoveryEvent
{
    public function __construct(public int $amount = 0) {}
}

// Valid handlers
#[AsEventHandler(handlerId: 'h.class', consumer: 'my.consumer')]
final class ClassLevelHandler
{
    public function __invoke(HandlerDiscoveryEvent $event): void {}
}

final class MethodLevelHandler
{
    #[AsEventHandler(handlerId: 'h.method', consumer: 'my.consumer')]
    public function handleEvent(HandlerDiscoveryEvent $event): void {}
}

final class HandlerWithEnvelopeInjection
{
    #[AsEventHandler(handlerId: 'h.env', consumer: 'my.consumer')]
    public function handle(HandlerDiscoveryEvent $event, EventEnvelope $envelope): void {}
}

final class HandlerWithMetadataInjection
{
    #[AsEventHandler(handlerId: 'h.meta', consumer: 'my.consumer')]
    public function handle(HandlerDiscoveryEvent $event, Metadata $meta): void {}
}

final class HandlerWithHeaderInjection
{
    #[AsEventHandler(handlerId: 'h.headers', consumer: 'my.consumer')]
    public function handle(
        HandlerDiscoveryEvent $event,
        #[MessageId] string $id,
        #[CorrelationId] string $corr,
        #[Header('x-tenant')] string $tenant,
    ): void {}
}

final class HandlerWithMetadataHeaderInjection
{
    #[AsEventHandler(handlerId: 'h.meta-headers', consumer: 'my.consumer')]
    public function handle(
        HandlerDiscoveryEvent $event,
        #[CausationId] ?string $causationId,
        #[TraceId] ?string $traceId,
        #[TenantId] ?string $tenantId,
        #[UserId] ?string $userId,
    ): void {}
}

// Invalid — F5: missing void
final class HandlerMissingVoidReturn
{
    #[AsEventHandler(handlerId: 'h.bad', consumer: 'c')]
    public function handle(HandlerDiscoveryEvent $event): bool { return true; }
}

// Invalid — F4: second non-special typed param
final class HandlerWithF4Violation
{
    #[AsEventHandler(handlerId: 'h.f4', consumer: 'c')]
    public function handle(HandlerDiscoveryEvent $event, AnotherDiscoveryEvent $other): void {}
}

// F1 violation event
class NonFinalDiscoveryEvent
{
    public function __construct(public readonly string $id = '') {}
}

final class HandlerForNonFinalEvent
{
    #[AsEventHandler(handlerId: 'h.f1', consumer: 'c')]
    public function handle(NonFinalDiscoveryEvent $event): void {}
}

// F3 violation event
final class EventWithExtraMethod
{
    public function __construct(public readonly string $id = '') {}
    public function getId(): string { return $this->id; }
}

final class HandlerForEventWithMethod
{
    #[AsEventHandler(handlerId: 'h.f3', consumer: 'c')]
    public function handle(EventWithExtraMethod $event): void {}
}

// F2 violation event — non-promoted property
final class EventWithNonPromotedProperty
{
    public readonly string $id;
    public function __construct(string $id = '') { $this->id = $id; }
}

final class HandlerForNonPromotedEvent
{
    #[AsEventHandler(handlerId: 'h.f2', consumer: 'c')]
    public function handle(EventWithNonPromotedProperty $event): void {}
}

final class HandlerDiscoveryCompilerPassTest extends TestCase
{
    private function container(): ContainerBuilder
    {
        $c = new ContainerBuilder();
        $c->setParameter('vortos.handlers', []);
        return $c;
    }

    private function register(ContainerBuilder $container, string $class): void
    {
        $def = new Definition($class);
        $def->addTag('vortos.event_handler');
        $container->setDefinition("test.$class", $def);
    }

    public function test_class_level_attribute_registers_descriptor(): void
    {
        $container = $this->container();
        $this->register($container, ClassLevelHandler::class);

        (new HandlerDiscoveryCompilerPass())->process($container);

        $handlers   = $container->getParameter('vortos.handlers');
        $descriptor = $handlers['my.consumer'][HandlerDiscoveryEvent::class][0];

        $this->assertSame('h.class', $descriptor['handlerId']);
        $this->assertSame('__invoke', $descriptor['method']);
        $this->assertSame(HandlerDiscoveryEvent::class, $descriptor['eventClass']);
        $this->assertSame([['type' => 'event', 'eventClass' => HandlerDiscoveryEvent::class]], $descriptor['parameters']);
    }

    public function test_method_level_attribute_registers_descriptor(): void
    {
        $container = $this->container();
        $this->register($container, MethodLevelHandler::class);

        (new HandlerDiscoveryCompilerPass())->process($container);

        $handlers   = $container->getParameter('vortos.handlers');
        $descriptor = $handlers['my.consumer'][HandlerDiscoveryEvent::class][0];

        $this->assertSame('h.method', $descriptor['handlerId']);
        $this->assertSame('handleEvent', $descriptor['method']);
    }

    public function test_envelope_injection_recognised_in_parameters(): void
    {
        $container = $this->container();
        $this->register($container, HandlerWithEnvelopeInjection::class);

        (new HandlerDiscoveryCompilerPass())->process($container);

        $params = $container->getParameter('vortos.handlers')['my.consumer'][HandlerDiscoveryEvent::class][0]['parameters'];

        $this->assertSame([
            ['type' => 'event', 'eventClass' => HandlerDiscoveryEvent::class],
            ['type' => 'envelope'],
        ], $params);
    }

    public function test_metadata_injection_recognised_in_parameters(): void
    {
        $container = $this->container();
        $this->register($container, HandlerWithMetadataInjection::class);

        (new HandlerDiscoveryCompilerPass())->process($container);

        $params = $container->getParameter('vortos.handlers')['my.consumer'][HandlerDiscoveryEvent::class][0]['parameters'];

        $this->assertSame([
            ['type' => 'event', 'eventClass' => HandlerDiscoveryEvent::class],
            ['type' => 'metadata'],
        ], $params);
    }

    public function test_header_injection_recognised_in_parameters(): void
    {
        $container = $this->container();
        $this->register($container, HandlerWithHeaderInjection::class);

        (new HandlerDiscoveryCompilerPass())->process($container);

        $params = $container->getParameter('vortos.handlers')['my.consumer'][HandlerDiscoveryEvent::class][0]['parameters'];

        $this->assertSame('header', $params[1]['type']);
        $this->assertSame(MessageId::class, $params[1]['attribute']);
        $this->assertSame('header', $params[2]['type']);
        $this->assertSame(CorrelationId::class, $params[2]['attribute']);
        $this->assertSame('header', $params[3]['type']);
        $this->assertSame(Header::class, $params[3]['attribute']);
        $this->assertSame('x-tenant', $params[3]['headerName']);
    }

    public function test_metadata_header_attributes_recognised_in_parameters(): void
    {
        $container = $this->container();
        $this->register($container, HandlerWithMetadataHeaderInjection::class);

        (new HandlerDiscoveryCompilerPass())->process($container);

        $params = $container->getParameter('vortos.handlers')['my.consumer'][HandlerDiscoveryEvent::class][0]['parameters'];

        $this->assertSame('header', $params[1]['type']);
        $this->assertSame(CausationId::class, $params[1]['attribute']);
        $this->assertSame('header', $params[2]['type']);
        $this->assertSame(TraceId::class, $params[2]['attribute']);
        $this->assertSame('header', $params[3]['type']);
        $this->assertSame(TenantId::class, $params[3]['attribute']);
        $this->assertSame('header', $params[4]['type']);
        $this->assertSame(UserId::class, $params[4]['attribute']);
    }

    public function test_throws_f5_when_return_type_is_not_void(): void
    {
        $container = $this->container();
        $this->register($container, HandlerMissingVoidReturn::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/F5/');

        (new HandlerDiscoveryCompilerPass())->process($container);
    }

    public function test_throws_f4_when_second_non_special_typed_param_appears(): void
    {
        $container = $this->container();
        $this->register($container, HandlerWithF4Violation::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/F4/');

        (new HandlerDiscoveryCompilerPass())->process($container);
    }

    public function test_throws_f1_when_event_class_not_final(): void
    {
        $container = $this->container();
        $this->register($container, HandlerForNonFinalEvent::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/F1/');

        (new HandlerDiscoveryCompilerPass())->process($container);
    }

    public function test_throws_f3_when_event_class_has_extra_method(): void
    {
        $container = $this->container();
        $this->register($container, HandlerForEventWithMethod::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/F3/');

        (new HandlerDiscoveryCompilerPass())->process($container);
    }

    public function test_throws_f2_when_event_property_not_promoted(): void
    {
        $container = $this->container();
        $this->register($container, HandlerForNonPromotedEvent::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/F2/');

        (new HandlerDiscoveryCompilerPass())->process($container);
    }

    public function test_throws_on_duplicate_handler_id(): void
    {
        $container = $this->container();
        $this->register($container, ClassLevelHandler::class);

        $def2 = new Definition(ClassLevelHandler::class);
        $def2->addTag('vortos.event_handler');
        $container->setDefinition('svc2', $def2);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Duplicate handlerId/');

        (new HandlerDiscoveryCompilerPass())->process($container);
    }
}
