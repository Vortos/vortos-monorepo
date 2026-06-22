<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Webhook;

/**
 * A webhook subscription: delivers signed payloads to a URL when flag/segment/rollout
 * events fire (Block 18). Scoped to project/env for multi-team isolation.
 */
final class WebhookSubscription
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        /** SHA-256 hash of the HMAC secret — raw secret never stored. */
        public readonly string $secretHash,
        /** @var list<string> event types this subscription listens to (e.g. 'flag.enabled') */
        public readonly array $eventTypes,
        public readonly ?string $projectId = null,
        public readonly ?string $environment = null,
        public readonly bool $active = true,
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {}

    public function matchesEvent(string $eventType, ?string $projectId, ?string $environment): bool
    {
        if (!$this->active) {
            return false;
        }

        if ($this->eventTypes !== [] && !in_array($eventType, $this->eventTypes, true) && !in_array('*', $this->eventTypes, true)) {
            return false;
        }

        if ($this->projectId !== null && $projectId !== null && $this->projectId !== $projectId) {
            return false;
        }

        if ($this->environment !== null && $environment !== null && $this->environment !== $environment) {
            return false;
        }

        return true;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'url'         => $this->url,
            'event_types' => $this->eventTypes,
            'project_id'  => $this->projectId,
            'environment' => $this->environment,
            'active'      => $this->active,
            'created_at'  => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
