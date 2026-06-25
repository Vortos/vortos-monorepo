<?php

declare(strict_types=1);

namespace Vortos\Observability\Audit;

use Vortos\Deploy\Audit\DeployAuditSinkInterface;
use Vortos\Deploy\Domain\Event\DeployAttempted;
use Vortos\Deploy\Domain\Event\DeployFailed;
use Vortos\Deploy\Domain\Event\DeployRefused;
use Vortos\Deploy\Domain\Event\DeploySucceeded;
use Vortos\Deploy\Domain\Event\RolledBack;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Observability\Sink\MessageScrubber;

/**
 * Projects every deploy/rollback decision into the tamper-evident audit read model
 * (Block 16, §3.1) — the Observability-side half of the seam Deploy declares via
 * {@see DeployAuditSinkInterface}. Applied **synchronously** in
 * {@see \Vortos\Deploy\Audit\DeployAuditRecorder}'s drain (idempotent upsert is not
 * needed here — sequence assignment happens inside the same locked append, so a
 * given envelope is appended exactly once).
 */
final class DeployAuditProjector implements DeployAuditProjectorInterface, DeployAuditSinkInterface
{
    public function __construct(
        private readonly DeployAuditViewRepositoryInterface $repository,
        private readonly string $hmacKey,
        private readonly AuditHashChain $chain = new AuditHashChain(),
        private readonly MessageScrubber $scrubber = new MessageScrubber(),
    ) {
    }

    public function handle(EventEnvelope $envelope): void
    {
        $this->apply($envelope);
    }

    public function apply(EventEnvelope $envelope): void
    {
        $event = $envelope->payload;
        $occurredAt = $envelope->occurredAt->format(\DateTimeInterface::ATOM);

        [$env, $eventType, $actorId, $actorIdentitySource, $buildId, $gitSha, $imageDigest, $schemaFingerprintId, $reason, $data] = match (true) {
            $event instanceof DeployAttempted => [
                $event->env, 'DeployAttempted', $event->actorId, $event->actorIdentitySource,
                $event->buildId, $event->gitSha, $event->imageDigest, $event->schemaFingerprintId,
                $event->reason, [],
            ],
            $event instanceof DeploySucceeded => [
                $event->env, 'DeploySucceeded', $event->actorId, $event->actorIdentitySource,
                $event->buildId, $event->gitSha, $event->imageDigest, $event->schemaFingerprintId,
                $event->reason, ['target_status_summary' => $this->scrubber->scrub($event->targetStatusSummary)],
            ],
            $event instanceof DeployRefused => [
                $event->env, 'DeployRefused', $event->actorId, $event->actorIdentitySource,
                $event->buildId, $event->gitSha, $event->imageDigest, $event->schemaFingerprintId,
                $event->reason, ['failed_check_ids' => $event->failedCheckIds],
            ],
            $event instanceof DeployFailed => [
                $event->env, 'DeployFailed', $event->actorId, $event->actorIdentitySource,
                $event->buildId, $event->gitSha, $event->imageDigest, $event->schemaFingerprintId,
                $event->reason, [
                    'error_class' => $event->errorClass,
                    'error_message' => $this->scrubber->scrub($event->errorMessage),
                ],
            ],
            $event instanceof RolledBack => [
                $event->env, 'RolledBack', $event->actorId, $event->actorIdentitySource,
                $event->fromBuildId, '', '', '',
                $event->reason, ['from_build_id' => $event->fromBuildId, 'to_build_id' => $event->toBuildId],
            ],
            default => throw new \InvalidArgumentException(sprintf(
                'DeployAuditProjector cannot handle payload of type "%s".',
                $event::class,
            )),
        };

        $entryId = $envelope->eventId;

        $this->repository->appendNext(
            $env,
            fn (int $sequence, string $prevHash) => $this->chain->chain(
                $entryId,
                $sequence,
                $eventType,
                $actorId,
                $actorIdentitySource,
                $env,
                $buildId,
                $gitSha,
                $imageDigest,
                $schemaFingerprintId,
                $reason,
                $occurredAt,
                $data,
                $prevHash,
                $this->hmacKey,
            ),
        );
    }
}
