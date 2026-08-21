<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Webhook\FlagEventListener;
use Vortos\FeatureFlags\Webhook\FlagWebhookMessagingConfig;
use Vortos\Messaging\Attribute\AsEventHandler;

/**
 * Every event the listener claims to handle must actually be routed to it.
 *
 * This exists because the listener shipped complete and unreachable: the event map was right,
 * the dispatch logic was right, and no handler was ever registered — so EventBus found nowhere
 * to send FlagCreatedEvent, logged a warning nobody read, and every webhook subscription in the
 * product was silently inert.
 *
 * The trap is specific and worth stating: the bus resolves handlers by their first typed
 * parameter, so `handleEvent(object $event)` can never be routed to no matter how it is tagged.
 * A listener can therefore look entirely finished while being, in the only sense that counts,
 * absent. This test compares intent (EVENT_MAP) against registration (the attributes) so the
 * two cannot drift apart again.
 */
final class FlagEventListenerWiringTest extends TestCase
{
    public function test_every_mapped_event_has_a_registered_handler(): void
    {
        $mapped     = array_keys($this->eventMap());
        $registered = $this->registeredEventClasses();

        sort($mapped);
        sort($registered);

        self::assertSame($mapped, $registered, sprintf(
            "FlagEventListener::EVENT_MAP and its #[AsEventHandler] methods disagree.\n"
            . "Unregistered (mapped but unreachable): %s\n"
            . "Unmapped (routed but ignored): %s",
            implode(', ', array_diff($mapped, $registered)) ?: 'none',
            implode(', ', array_diff($registered, $mapped)) ?: 'none',
        ));
    }

    public function test_handlers_are_registered_to_the_in_process_consumer(): void
    {
        // The consumer must exist and be in-process, or the handlers resolve to a Kafka
        // consumer nobody runs — unwired again, in a way that looks wired.
        $consumer = (new FlagWebhookMessagingConfig())->flagWebhookConsumer()->toArray();

        self::assertTrue($consumer['inProcess'] ?? false, 'Flag webhooks must dispatch in-process.');

        foreach ($this->handlerAttributes() as $attribute) {
            self::assertSame(FlagWebhookMessagingConfig::CONSUMER, $attribute->consumer);
        }
    }

    public function test_no_handler_takes_an_untyped_or_object_parameter(): void
    {
        // The precise defect: an `object`-typed parameter cannot be routed to.
        foreach ((new \ReflectionClass(FlagEventListener::class))->getMethods() as $method) {
            if ($method->getAttributes(AsEventHandler::class) === []) {
                continue;
            }

            $params = $method->getParameters();
            self::assertCount(1, $params, $method->getName() . ' must take exactly one event.');

            $type = $params[0]->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type, $method->getName() . ' must type its event.');
            self::assertNotSame('object', $type->getName(), $method->getName() . ' takes `object`, which cannot be routed to.');
        }
    }

    /** @return array<class-string, string> */
    private function eventMap(): array
    {
        $property = new \ReflectionClassConstant(FlagEventListener::class, 'EVENT_MAP');

        /** @var array<class-string, string> $map */
        $map = $property->getValue();

        return $map;
    }

    /** @return list<class-string> */
    private function registeredEventClasses(): array
    {
        $classes = [];

        foreach ((new \ReflectionClass(FlagEventListener::class))->getMethods() as $method) {
            if ($method->getAttributes(AsEventHandler::class) === []) {
                continue;
            }

            $type = $method->getParameters()[0]->getType();

            if ($type instanceof \ReflectionNamedType) {
                $classes[] = $type->getName();
            }
        }

        return $classes;
    }

    /** @return list<AsEventHandler> */
    private function handlerAttributes(): array
    {
        $attributes = [];

        foreach ((new \ReflectionClass(FlagEventListener::class))->getMethods() as $method) {
            foreach ($method->getAttributes(AsEventHandler::class) as $attribute) {
                $attributes[] = $attribute->newInstance();
            }
        }

        return $attributes;
    }
}
