<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier;

use Throwable;
use Vortos\Observability\Buffer\BoundedSpool;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Decorator over the resolved driver (§3.6): writes to a {@see BoundedSpool}-backed
 * crash-safe outbox keyed by idempotency key, drains via the real driver, re-spools
 * the undelivered remainder on failure (mirrors `OutboxMarkerEmitter` /
 * `GlitchtipErrorSink::flush()` exactly). A notifier outage **cannot** block/fail the
 * emitter, and a retry **never double-pages** (idempotency key).
 */
final class OutboxNotifier implements NotifierInterface
{
    public function __construct(
        private readonly NotifierInterface $inner,
        private readonly BoundedSpool $spool,
        private readonly DeliveryDedupeStoreInterface $dedupe = new InMemoryDeliveryDedupeStore(),
    ) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    /** Enqueue, never throws; the caller gets a durable hand-off, not a delivery guarantee. */
    public function notify(NotifierMessage $message): NotificationResult
    {
        if ($this->dedupe->seen($message->idempotencyKey)) {
            return NotificationResult::deduped($this->name(), 'idempotency key already delivered');
        }

        try {
            $this->spool->enqueue(json_encode($this->encode($message), JSON_THROW_ON_ERROR));
            $this->dedupe->remember($message->idempotencyKey);
        } catch (Throwable) {
            return NotificationResult::failed($this->name(), 'failed to enqueue to outbox');
        }

        return NotificationResult::delivered($this->name());
    }

    /**
     * Drains everything currently buffered through the real driver. On a delivery
     * failure the undelivered remainder is re-spooled in order and the drain stops,
     * so a transient outage never loses or reorders a notification.
     *
     * @return list<NotificationResult>
     */
    public function drain(int $batch = 100): array
    {
        $records = $this->spool->drain($batch);
        $results = [];

        foreach ($records as $index => $record) {
            $message = $this->decode($record->payload);
            if ($message === null) {
                continue; // Malformed record — drop it, never blocks the rest.
            }

            $result = $this->inner->notify($message);
            $results[] = $result;

            if (!$result->isSuccess()) {
                for ($i = $index, $n = count($records); $i < $n; $i++) {
                    $this->spool->enqueue($records[$i]->payload, $records[$i]->enqueuedAtMs);
                }

                return $results;
            }
        }

        return $results;
    }

    public function capabilities(): CapabilityDescriptor
    {
        return $this->inner->capabilities();
    }

    /** @return array<string, mixed> */
    private function encode(NotifierMessage $message): array
    {
        return [
            'idempotency_key' => $message->idempotencyKey,
            'severity' => $message->severity->value,
            'title' => $message->title,
            'body' => $message->body,
            'fields' => $message->fields,
            'links' => $message->links,
            'runbook_url' => $message->runbookUrl,
        ];
    }

    private function decode(string $payload): ?NotifierMessage
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

            return new NotifierMessage(
                idempotencyKey: (string) $data['idempotency_key'],
                severity: \Vortos\Alerts\Severity::from((string) $data['severity']),
                title: (string) $data['title'],
                body: (string) $data['body'],
                fields: (array) $data['fields'],
                links: (array) $data['links'],
                runbookUrl: $data['runbook_url'] !== null ? (string) $data['runbook_url'] : null,
            );
        } catch (Throwable) {
            return null;
        }
    }
}
