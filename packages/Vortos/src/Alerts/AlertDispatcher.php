<?php

declare(strict_types=1);

namespace Vortos\Alerts;

use Vortos\Alerts\Dedupe\Dedupe;
use Vortos\Alerts\Dedupe\DedupeDecision;
use Vortos\Alerts\Dedupe\DedupeWindow;
use Vortos\Alerts\Dedupe\Fingerprint;
use Vortos\Alerts\Integration\Audit\AlertAuditRecorderInterface;
use Vortos\Alerts\Routing\RoutedDelivery;
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
        /**
         * Tamper-evident record of who was paged and what came of it. Optional: a container with
         * no audit ledger configured still alerts, it just keeps no history.
         *
         * This was registered in the DI extension but never referenced by anything, so
         * `alerts_audit_log` could not be written no matter how many alerts fired — the ledger
         * existed and recorded nothing. Detection and delivery were unaffected, which is precisely
         * why it went unnoticed: the gap only shows up afterwards, when someone asks whether an
         * alert fired and there is no way to answer.
         */
        private readonly ?AlertAuditRecorderInterface $auditRecorder = null,
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

            $result = $notifier->notify($message);
            $results[] = $result;

            // Record the ATTEMPT and its outcome, not just successes: "we tried to page you and
            // the webhook rejected it" is the entry an incident review most needs, and it is the
            // one a success-only log would omit.
            $this->recordNotification($event, $delivery, $result, $now);
        }

        return new DispatchResult($outcome->decision, $results);
    }

    /**
     * Auditing must never be able to suppress an alert. A ledger write that fails — disk, schema
     * drift, a broken hash chain — is a bookkeeping problem; losing the page it describes is an
     * operational one. So this swallows its own failure by design, and the absence of entries is
     * itself caught by the ledger's own verification rather than by breaking delivery.
     */
    private function recordNotification(
        AlertEvent $event,
        RoutedDelivery $delivery,
        NotificationResult $result,
        \DateTimeImmutable $now,
    ): void {
        if ($this->auditRecorder === null) {
            return;
        }

        try {
            $this->auditRecorder->recordNotification($event, $delivery, $result, $now);
        } catch (\Throwable) {
            // Deliberately ignored — see the note above.
        }
    }
}
