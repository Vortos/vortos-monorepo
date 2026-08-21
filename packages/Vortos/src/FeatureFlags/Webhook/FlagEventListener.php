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
use Vortos\Messaging\Attribute\AsEventHandler;

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

    #[AsEventHandler(handlerId: 'vortos.flags.webhook.created', consumer: FlagWebhookMessagingConfig::CONSUMER)]
    public function onCreated(FlagCreatedEvent $event): void { $this->handleEvent($event); }

    #[AsEventHandler(handlerId: 'vortos.flags.webhook.enabled', consumer: FlagWebhookMessagingConfig::CONSUMER)]
    public function onEnabled(FlagEnabledEvent $event): void { $this->handleEvent($event); }

    #[AsEventHandler(handlerId: 'vortos.flags.webhook.disabled', consumer: FlagWebhookMessagingConfig::CONSUMER)]
    public function onDisabled(FlagDisabledEvent $event): void { $this->handleEvent($event); }

    #[AsEventHandler(handlerId: 'vortos.flags.webhook.rules_changed', consumer: FlagWebhookMessagingConfig::CONSUMER)]
    public function onRulesChanged(FlagRulesChangedEvent $event): void { $this->handleEvent($event); }

    #[AsEventHandler(handlerId: 'vortos.flags.webhook.variants_changed', consumer: FlagWebhookMessagingConfig::CONSUMER)]
    public function onVariantsChanged(FlagVariantsChangedEvent $event): void { $this->handleEvent($event); }

    #[AsEventHandler(handlerId: 'vortos.flags.webhook.scheduled', consumer: FlagWebhookMessagingConfig::CONSUMER)]
    public function onScheduled(FlagScheduledEvent $event): void { $this->handleEvent($event); }

    #[AsEventHandler(handlerId: 'vortos.flags.webhook.archived', consumer: FlagWebhookMessagingConfig::CONSUMER)]
    public function onArchived(FlagArchivedEvent $event): void { $this->handleEvent($event); }

    /**
     * Handle a domain event — resolve its type and dispatch to matching webhooks.
     *
     * One registered method per event rather than a single object-typed handler, because the
     * bus resolves handlers by the first typed parameter: a `handleEvent(object $event)` cannot
     * be routed to, which is why this listener sat unwired while looking complete. The methods
     * above are the registration; this remains the shared body.
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
