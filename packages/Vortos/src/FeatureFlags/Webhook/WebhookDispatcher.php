<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Webhook;

use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Dispatches signed webhook deliveries to matching subscriptions (Block 18).
 *
 * The dispatcher is called from an event handler/projection — NOT inline in the flag
 * mutation request. The actual HTTP delivery is delegated to a {@see WebhookDeliveryInterface}
 * implementation (which handles retry/backoff).
 *
 * Security: every URL is re-validated through the SSRF guard before delivery. Secrets
 * are retrieved per-delivery (never cached in memory longer than one dispatch cycle).
 */
final class WebhookDispatcher
{
    public function __construct(
        private readonly WebhookStorageInterface $storage,
        private readonly WebhookDeliveryInterface $delivery,
        private readonly SsrfGuard $ssrfGuard,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Dispatch a flag event to all matching webhook subscriptions.
     *
     * @param array<string,mixed> $eventData the event payload
     */
    public function dispatch(
        string $eventType,
        array $eventData,
        ?string $projectId = null,
        ?string $environment = null,
    ): int {
        $subscriptions = $this->storage->findActive();
        $dispatched    = 0;

        foreach ($subscriptions as $sub) {
            if (!$sub->matchesEvent($eventType, $projectId, $environment)) {
                continue;
            }

            // Re-validate URL against SSRF guard
            $validation = $this->ssrfGuard->validate($sub->url);
            if (!$validation['safe']) {
                $this->logger?->warning('Webhook delivery blocked by SSRF guard.', [
                    'subscription_id' => $sub->id,
                    'url'             => $sub->url,
                    'reason'          => $validation['reason'],
                ]);
                continue;
            }

            $deliveryId = Uuid::v7()->toRfc4122();
            $payload    = new WebhookPayload(
                subscriptionId: $sub->id,
                eventType:      $eventType,
                data:           $eventData,
                timestamp:      date(\DateTimeInterface::ATOM),
                deliveryId:     $deliveryId,
            );

            $this->delivery->deliver($payload, $sub, $validation['resolved_ip']);
            $dispatched++;
        }

        return $dispatched;
    }
}
