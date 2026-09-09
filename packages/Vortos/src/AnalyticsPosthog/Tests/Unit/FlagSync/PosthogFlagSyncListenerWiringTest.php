<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\Tests\Unit\FlagSync;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Vortos\AnalyticsPosthog\FlagSync\PosthogFlagSyncListener;
use Vortos\AnalyticsPosthog\FlagSync\PosthogFlagSyncMessagingConfig;
use Vortos\Messaging\Attribute\AsEventHandler;

/**
 * Guards the failure mode this codebase has already paid for twice: a listener that ships
 * complete and unreachable.
 *
 * The flag webhooks sat inert behind a missing registration, and a batch of event handlers
 * never fired because no consumer group was wired — in both cases silently, for months. The
 * specific trap is that the bus routes on the first typed parameter, so a handler taking
 * `object` can never be routed to no matter how it is tagged.
 */
final class PosthogFlagSyncListenerWiringTest extends TestCase
{
    /** Every flag change that alters what we mirror must reach the listener. */
    private const MUST_HANDLE = [
        'Vortos\\FeatureFlags\\Domain\\Event\\FlagCreatedEvent',
        'Vortos\\FeatureFlags\\Domain\\Event\\FlagEnabledEvent',
        'Vortos\\FeatureFlags\\Domain\\Event\\FlagDisabledEvent',
        'Vortos\\FeatureFlags\\Domain\\Event\\FlagVariantsChangedEvent',
        'Vortos\\FeatureFlags\\Domain\\Event\\FlagReconfiguredEvent',
        'Vortos\\FeatureFlags\\Domain\\Event\\FlagLifecycleChangedEvent',
        'Vortos\\FeatureFlags\\Domain\\Event\\FlagArchivedEvent',
    ];

    public function test_creating_a_flag_is_routed_to_the_listener(): void
    {
        // The headline promise: adding a flag makes it appear in PostHog by itself.
        $this->assertContains(
            'Vortos\\FeatureFlags\\Domain\\Event\\FlagCreatedEvent',
            $this->registeredEventClasses(),
        );
    }

    public function test_every_change_that_alters_the_mirror_is_handled(): void
    {
        $registered = $this->registeredEventClasses();
        $missing = array_diff(self::MUST_HANDLE, $registered);

        $this->assertSame([], array_values($missing), 'unhandled flag changes leave PostHog showing a stale definition');
    }

    public function test_handlers_are_registered_to_the_in_process_consumer(): void
    {
        // A Kafka consumer nobody runs is the same as no handler at all — and looks wired.
        $consumer = (new PosthogFlagSyncMessagingConfig())->flagSyncConsumer()->toArray();

        $this->assertTrue($consumer['inProcess'] ?? false);

        foreach ($this->handlerAttributes() as $attribute) {
            $this->assertSame(PosthogFlagSyncMessagingConfig::CONSUMER, $attribute->consumer);
        }
    }

    public function test_no_handler_takes_an_object_parameter(): void
    {
        foreach ($this->handlerMethods() as $method) {
            $params = $method->getParameters();
            $this->assertCount(1, $params, $method->getName() . ' must take exactly one event.');

            $type = $params[0]->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type, $method->getName() . ' must type its event.');
            $this->assertNotSame('object', $type->getName(), $method->getName() . ' takes `object`, which cannot be routed to.');
        }
    }

    public function test_handler_ids_are_unique(): void
    {
        // Two handlers sharing an id silently collapse into one registration.
        $ids = array_map(static fn (AsEventHandler $a): ?string => $a->handlerId, $this->handlerAttributes());

        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    /** @return list<string> */
    private function registeredEventClasses(): array
    {
        $classes = [];

        foreach ($this->handlerMethods() as $method) {
            $type = $method->getParameters()[0]->getType();
            if ($type instanceof ReflectionNamedType) {
                $classes[] = $type->getName();
            }
        }

        return $classes;
    }

    /** @return list<\ReflectionMethod> */
    private function handlerMethods(): array
    {
        $methods = [];

        foreach ((new ReflectionClass(PosthogFlagSyncListener::class))->getMethods() as $method) {
            if ($method->getAttributes(AsEventHandler::class) !== []) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /** @return list<AsEventHandler> */
    private function handlerAttributes(): array
    {
        $attributes = [];

        foreach ($this->handlerMethods() as $method) {
            foreach ($method->getAttributes(AsEventHandler::class) as $attribute) {
                $attributes[] = $attribute->newInstance();
            }
        }

        return $attributes;
    }
}
