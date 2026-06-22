<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Webhook;

use Vortos\FeatureFlags\Domain\Event\FlagArchivedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagCreatedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagDisabledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagEnabledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagRulesChangedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagScheduledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagVariantsChangedEvent;

/**
 * Listens to flag domain events and dispatches webhooks (Block 18).
 *
 * Mapped to the event types the `WebhookSubscription` can subscribe to.
 */
final class FlagEventListener
{
    /** Maps domain event class → webhook event type string. */
    private const EVENT_MAP = [
        FlagCreatedEvent::class         => 'flag.created',
        FlagEnabledEvent::class         => 'flag.enabled',
        FlagDisabledEvent::class        => 'flag.disabled',
        FlagRulesChangedEvent::class    => 'flag.rules_changed',
        FlagVariantsChangedEvent::class => 'flag.variants_changed',
        FlagScheduledEvent::class       => 'flag.scheduled',
        FlagArchivedEvent::class        => 'flag.archived',
    ];

    public function __construct(
        private readonly WebhookDispatcher $dispatcher,
    ) {}

    /**
     * Handle a domain event — resolve its type and dispatch to matching webhooks.
     */
    public function handleEvent(object $event): void
    {
        $eventClass = get_class($event);
        $eventType  = self::EVENT_MAP[$eventClass] ?? null;

        if ($eventType === null) {
            return;
        }

        $data = $this->extractData($event);

        $this->dispatcher->dispatch(
            $eventType,
            $data,
            $data['project_id'] ?? null,
            $data['environment'] ?? null,
        );
    }

    private function extractData(object $event): array
    {
        // All flag domain events are pure POPOs with public readonly props.
        $data = [];
        $ref  = new \ReflectionClass($event);

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($event);

            if ($value instanceof \DateTimeInterface) {
                $value = $value->format(\DateTimeInterface::ATOM);
            } elseif (is_object($value) && method_exists($value, 'toArray')) {
                $value = $value->toArray();
            } elseif ($value instanceof \BackedEnum) {
                $value = $value->value;
            }

            $data[$prop->getName()] = $value;
        }

        return $data;
    }
}
