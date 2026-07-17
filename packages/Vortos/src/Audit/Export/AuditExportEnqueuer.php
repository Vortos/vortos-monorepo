<?php

declare(strict_types=1);

namespace Vortos\Audit\Export;

use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;
use Vortos\Audit\Enum\Scope;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Messaging\Contract\StandaloneEventBusInterface;

/**
 * Producer side of async export: persists a Queued {@see AuditExportJob}, then dispatches an
 * {@see AuditExportRequested} envelope (→ outbox → Kafka) for the export consumer to run.
 *
 * Uses the STANDALONE event bus (not the transactional one), mirroring {@see \Vortos\Audit\Ingestion\AsyncAuditRecorder}:
 * the enqueue happens from an HTTP request handler that is not necessarily inside a business
 * DB transaction, and the standalone bus opens its own transaction when none is active (joining
 * one when present) so the outbox write always succeeds. The job row is saved BEFORE dispatch,
 * so a status poll can always find the job even if the broker is briefly slow.
 *
 * The envelope's aggregateId is the chain key (scope/tenant), so all exports for one tenant
 * co-partition — the same routing discipline the audit ingestion path uses.
 */
final class AuditExportEnqueuer
{
    public function __construct(
        private readonly AuditExportJobStoreInterface $jobs,
        private readonly StandaloneEventBusInterface  $eventBus,
        private readonly ClockInterface               $clock,
    ) {}

    public function enqueue(
        Scope             $scope,
        ?string           $tenantId,
        string            $requestedByActorId,
        ?string           $requestedByLabel,
        AuditExportFilter $filter,
    ): AuditExportJob {
        $id  = (string) new UuidV7();
        $job = AuditExportJob::queue(
            id:                 $id,
            scope:              $scope,
            tenantId:           $tenantId,
            requestedByActorId: $requestedByActorId,
            requestedByLabel:   $requestedByLabel,
            filter:             $filter,
            now:                $this->clock->now(),
        );

        $this->jobs->save($job);

        $this->eventBus->dispatch(new EventEnvelope(
            eventId:          (string) new UuidV7(),
            aggregateId:      $this->chainKey($scope, $tenantId),
            aggregateType:    'AuditExportJob',
            aggregateVersion: 0,
            payloadType:      AuditExportRequested::class,
            schemaVersion:    1,
            occurredAt:       $this->clock->now(),
            payload:          new AuditExportRequested($id),
            metadata:         Metadata::empty(),
        ));

        return $job;
    }

    private function chainKey(Scope $scope, ?string $tenantId): string
    {
        return $scope->requiresTenantId() ? 'tenant:' . (string) $tenantId : 'platform';
    }
}
