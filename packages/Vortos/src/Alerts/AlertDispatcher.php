<?php

declare(strict_types=1);

namespace Vortos\Alerts;

use Vortos\Alerts\Dedupe\Dedupe;
use Vortos\Alerts\Dedupe\DedupeDecision;
use Vortos\Alerts\Dedupe\DedupeWindow;
use Vortos\Alerts\Dedupe\Fingerprint;
use Vortos\Alerts\Dedupe\AlertStateStoreInterface;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Notifier\NotificationResult;
use Vortos\Alerts\Notifier\NotifierMessage;
use Vortos\Alerts\Notifier\NotifierRegistry;
use Vortos\Alerts\RateLimit\OutboundRateLimiterInterface;
use Vortos\Alerts\RateLimit\RateLimitDecision;
use Vortos\Alerts\Routing\Router;

/**
 * Wires stage 1 (dedupe) → stage 2 (routing) → the delivery seam (§3, architecture
 * diagram). Stage 3 (escalation/ack/quiet-hours/silences) is a separate, independently
 * tested out-of-band concern ({@see \Vortos\Alerts\Escalation\EscalationEngine}) driven
 * by its own scheduled tick — this dispatcher is the synchronous "an event just fired"
 * path: dedupe collapses a storm, routing decides the channel set, delivery never
 * blocks (each resolved notifier is outbox-backed).
 */
final class AlertDispatcher implements AlertDispatcherInterface
{
    public function __construct(
        private readonly Dedupe $dedupe,
        private readonly AlertStateStoreInterface $stateStore,
        private readonly DedupeWindow $window,
        private readonly Router $router,
        private readonly NotifierRegistry $notifiers,
        private readonly OutboundRateLimiterInterface $rateLimiter,
    ) {}

    public function dispatch(AlertEvent $event, ?array $routingOverride = null): DispatchResult
    {
        $fingerprint = Fingerprint::of($event);
        $previous = $this->stateStore->get($fingerprint);
        $now = $event->occurredAt;

        $outcome = $this->dedupe->evaluate($event, $previous, $this->window, $now);
        $this->stateStore->save($outcome->nextState);

        if ($outcome->decision === DedupeDecision::Deduped) {
            return new DispatchResult($outcome->decision, []);
        }

        $deliveries = $this->router->route($event, $routingOverride);
        $results = [];

        $tenantId = $event->tenantId ?? '__global__';

        foreach ($deliveries as $delivery) {
            $decision = $this->rateLimiter->tryConsume($tenantId, $delivery->notifierKey);

            if ($decision !== RateLimitDecision::Allowed) {
                $results[] = NotificationResult::rateLimited(
                    $delivery->channelKey,
                    $decision->value,
                );
                continue;
            }

            $notifier = $this->notifiers->notifier($delivery->notifierKey);
            $message = new NotifierMessage(
                idempotencyKey: hash('sha256', $fingerprint . '|' . $delivery->channelKey),
                severity: $event->severity,
                title: $event->title,
                body: $event->summary,
                fields: [...$event->labels, ...$event->annotations],
                links: $event->links,
                runbookUrl: $event->runbookUrl,
            );

            $results[] = $notifier->notify($message);
        }

        return new DispatchResult($outcome->decision, $results);
    }
}
