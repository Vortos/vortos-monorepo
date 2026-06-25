<?php

declare(strict_types=1);

namespace Vortos\Observability\Marker;

use Throwable;
use Vortos\Observability\Buffer\BoundedSpool;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Decorator in front of a real {@see MarkerEmitterInterface} that writes to a
 * {@see BoundedSpool}-backed local outbox and drains via the real emitter (Block
 * 16, §3.2) — a backend outage cannot block or fail a deploy. Idempotency key
 * ({@see DeployMarker::idempotencyKey()}) is recorded in a small on-disk seen-set
 * so a retried deploy never double-annotates, even across process restarts.
 */
final class OutboxMarkerEmitter implements MarkerEmitterInterface
{
    public function __construct(
        private readonly MarkerEmitterInterface $inner,
        private readonly BoundedSpool $spool,
        private readonly DedupeStore $dedupe = new InMemoryDedupeStore(),
    ) {
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    /** Enqueue, never throws. */
    public function emit(DeployMarker $marker): void
    {
        if ($this->dedupe->seen($marker->idempotencyKey())) {
            return;
        }

        try {
            $this->spool->enqueue(json_encode($marker->toArray(), JSON_THROW_ON_ERROR));
            $this->dedupe->remember($marker->idempotencyKey());
        } catch (Throwable) {
            // Spool failure must never fail the deploy.
        }
    }

    /**
     * Drains everything currently buffered through the real emitter. Returns the
     * number of markers successfully drained. Mirrors
     * {@see \Vortos\Observability\Driver\Glitchtip\GlitchtipErrorSink::flush()}:
     * on a delivery failure (the inner emitter threw — defensive only, since the
     * production contract is "never throws") the undelivered remainder is
     * re-spooled in order and the drain stops, so a transient failure never loses
     * a marker.
     */
    public function drain(int $batch = 100): int
    {
        $records = $this->spool->drain($batch);
        $drained = 0;

        foreach ($records as $index => $record) {
            try {
                $marker = $this->decode($record->payload);
                if ($marker === null) {
                    continue; // Malformed record — drop it, never blocks the rest.
                }

                $this->inner->emit($marker);
                $drained++;
            } catch (Throwable) {
                // Re-spool this record and everything after it; preserve order; stop.
                for ($i = $index, $n = count($records); $i < $n; $i++) {
                    $this->spool->enqueue($records[$i]->payload, $records[$i]->enqueuedAtMs);
                }

                return $drained;
            }
        }

        return $drained;
    }

    private function decode(string $payload): ?DeployMarker
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

            return new DeployMarker(
                env: (string) $data['env'],
                kind: (string) $data['kind'],
                buildId: (string) $data['build_id'],
                gitSha: (string) $data['git_sha'],
                imageDigest: (string) $data['image_digest'],
                schemaFingerprintId: (string) $data['schema_fingerprint_id'],
                title: (string) $data['title'],
                tags: (array) $data['tags'],
                at: new \DateTimeImmutable((string) $data['at']),
                links: (array) $data['links'],
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function capabilities(): CapabilityDescriptor
    {
        return $this->inner->capabilities();
    }
}
