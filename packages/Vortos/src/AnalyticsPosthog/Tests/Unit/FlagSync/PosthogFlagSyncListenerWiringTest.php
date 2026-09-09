<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\Tests\Unit\FlagSync;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Vortos\AnalyticsPosthog\FlagSync\PosthogFlagSyncListener;
use Vortos\AnalyticsPosthog\FlagSync\PosthogFlagSyncMessagingConfig;
use Vortos\Messaging\Attribute\AsEventHandler;
use Vortos\Messaging\Attribute\RegisterTransport;

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

    public function test_the_consumer_has_a_transport_of_its_own_name(): void
    {
        // Not a nicety: MessagingConfigCompilerPass requires every consumer to resolve a
        // transport of its own name, and applies that to inProcess() consumers too. A package
        // that registers a consumer without one does not degrade a feature — it throws while
        // the container compiles, so the application cannot boot and every gate that boots it
        // fails. This package must be self-contained; an app should never have to supply the
        // missing half to start.
        $config = new PosthogFlagSyncMessagingConfig();

        $consumer  = $config->flagSyncConsumer()->toArray();
        $transport = $config->flagSyncTransport()->toArray();

        // The exact resolution the compiler pass performs: the consumer names a transport,
        // and a transport of that name must exist.
        $this->assertSame($consumer['transport'], $transport['name']);
        $this->assertSame(PosthogFlagSyncMessagingConfig::CONSUMER, $transport['name']);
        $this->assertSame('in_memory', $transport['driver'], 'an in-process consumer must not provision a broker topic');
    }

    public function test_the_transport_is_declared_with_the_registration_attribute(): void
    {
        // A definition method the container never sees registers nothing.
        $method = new \ReflectionMethod(PosthogFlagSyncMessagingConfig::class, 'flagSyncTransport');

        $this->assertNotEmpty(
            $method->getAttributes(RegisterTransport::class),
            'flagSyncTransport() must carry #[RegisterTransport] or it is never registered.',
        );
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
