<?php

declare(strict_types=1);

namespace Vortos\Audit\Ingestion;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vortos\Audit\Contract\AuditRecorderInterface;
use Vortos\Audit\Event\AuditEvent;
use Vortos\Audit\Ingestion\Idempotency\IdempotencyGuardInterface;
use Vortos\Audit\Observability\AuditMetrics;

/**
 * The consumer-side worker: takes a delivered event and appends it to its chain, exactly
 * once. Two dedup layers: the Redis fast-path guard avoids a DB round-trip for an already
 * seen id; the store's primary key on the id is the cross-process authority (a duplicate
 * insert is caught and treated as success). The guard claim is released on a genuine
 * append failure so a redelivery can retry.
 *
 * The injected recorder MUST be the terminal store (DbalAuditStore), never the async
 * recorder — otherwise ingestion would re-enqueue itself.
 */
final class AuditIngestionProcessor
{
    private const KEY_PREFIX = 'audit:idem:';

    public function __construct(
        private readonly AuditRecorderInterface     $store,
        private readonly IdempotencyGuardInterface  $guard,
        private readonly int                        $idempotencyTtlSeconds = 604800,
        private readonly LoggerInterface            $logger = new NullLogger(),
        private readonly ?AuditMetrics              $metrics = null,
    ) {}

    public function process(AuditEvent $event): void
    {
        $key = self::KEY_PREFIX . $event->id;

        if (!$this->guard->claim($key, $this->idempotencyTtlSeconds)) {
            $this->logger->debug('Audit ingestion skipped: duplicate delivery.', ['audit_id' => $event->id]);
            $this->metrics?->duplicateSkipped();
            return;
        }

        try {
            $this->store->record($event);
            $this->metrics?->ingested($event->scope->value);
        } catch (\Throwable $e) {
            if ($this->isDuplicateKey($e)) {
                // Already persisted by an earlier delivery — idempotent success.
                $this->logger->debug('Audit ingestion: id already persisted, treating as done.', ['audit_id' => $event->id]);
                $this->metrics?->duplicateSkipped();
                return;
            }
            // Genuine failure — release the claim so redelivery retries, then surface.
            $this->guard->release($key);
            throw $e;
        }
    }

    private function isDuplicateKey(\Throwable $e): bool
    {
        // Doctrine DBAL surfaces PK/unique violations as UniqueConstraintViolationException;
        // string-match keeps this decoupled from a hard DBAL dependency.
        $class = $e::class;
        if (str_contains($class, 'UniqueConstraintViolation')) {
            return true;
        }
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'duplicate') || str_contains($msg, 'unique constraint');
    }
}
