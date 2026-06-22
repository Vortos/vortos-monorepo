<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vortos\FeatureFlags\ChangeRequest\Domain\Event\ChangeRequestExpiredEvent;
use Vortos\FeatureFlags\ChangeRequest\Domain\Event\ChangeRequestStatusChangedEvent;
use Vortos\FeatureFlags\ChangeRequest\Storage\ChangeRequestStorageInterface;
use Vortos\FeatureFlags\ChangeRequest\Support\EventEnvelopeFactory;
use Vortos\FeatureFlags\SystemClock;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

/**
 * Block 14 — the scheduled sweeper that applies due, approved change requests and expires
 * stale ones. Intended to be driven by {@see \Vortos\FeatureFlags\Command\FlagsProcessChangeRequestsCommand}
 * on a cron / worker tick.
 *
 * A Postgres advisory lock guards the whole sweep so that concurrent ticks (multiple
 * cron hosts, overlapping runs) never double-apply a request. Individual apply failures
 * are isolated — one bad request never blocks the rest of the batch.
 */
final class ChangeRequestProcessor
{
    private const ADVISORY_LOCK_KEY = 0x46466372; // "FFcr"

    private readonly ClockInterface $clock;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ChangeRequestStorageInterface $storage,
        private readonly ChangeRequestService $service,
        private readonly Connection $connection,
        private readonly UnitOfWorkInterface $unitOfWork,
        private readonly EventBusInterface $eventBus,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->clock  = $clock ?? new SystemClock();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Apply every approved change request whose schedule is due. Returns the number of
     * requests successfully applied.
     */
    public function processScheduledApplications(string $actorId): int
    {
        if (!$this->acquireLock()) {
            return 0;
        }

        try {
            $applied = 0;

            foreach ($this->storage->findDueForApplication() as $request) {
                // Re-check status defensively: another worker may have applied it between
                // the query and the lock, or the row may have been cancelled.
                if ($request->status() !== ChangeRequestStatus::Approved) {
                    continue;
                }

                try {
                    $this->service->apply($request->id(), $actorId);
                    $applied++;
                } catch (\Throwable $e) {
                    $this->logger->error('Change request apply failed during sweep', [
                        'changeRequestId' => $request->id(),
                        'flag'            => $request->flagName(),
                        'error'           => $e->getMessage(),
                    ]);
                }
            }

            return $applied;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Mark every pending/approved request past its TTL as expired. Returns the count
     * expired.
     */
    public function processExpired(): int
    {
        $expired = 0;

        foreach ($this->storage->findExpired() as $request) {
            try {
                $expired += $this->unitOfWork->run(function () use ($request): int {
                    $previous = $request->status();
                    $request->markExpired();
                    $this->storage->save($request);

                    $now = $this->clock->now();
                    $this->eventBus->dispatch(EventEnvelopeFactory::wrap(
                        $request->id(),
                        new ChangeRequestExpiredEvent($request->id(), $request->flagName(), $now),
                        $now,
                    ));
                    $this->eventBus->dispatch(EventEnvelopeFactory::wrap(
                        $request->id(),
                        new ChangeRequestStatusChangedEvent($request->id(), $request->flagName(), $previous, ChangeRequestStatus::Expired, $now),
                        $now,
                    ));

                    return 1;
                });
            } catch (\Throwable $e) {
                $this->logger->error('Change request expiry failed during sweep', [
                    'changeRequestId' => $request->id(),
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        return $expired;
    }

    private function acquireLock(): bool
    {
        try {
            return (bool) $this->connection->fetchOne('SELECT pg_try_advisory_lock(?)', [self::ADVISORY_LOCK_KEY]);
        } catch (\Throwable) {
            // Platforms without advisory locks (e.g. SQLite in tests) — proceed without
            // the cross-process guard; the per-request status re-check is the fallback.
            return true;
        }
    }

    private function releaseLock(): void
    {
        try {
            $this->connection->fetchOne('SELECT pg_advisory_unlock(?)', [self::ADVISORY_LOCK_KEY]);
        } catch (\Throwable) {
            // No-op when advisory locks are unsupported.
        }
    }
}
