<?php

declare(strict_types=1);

namespace Vortos\Observability\Marker;

use Vortos\Deploy\Audit\DeployAuditSinkInterface;
use Vortos\Deploy\Domain\Event\DeployAttempted;
use Vortos\Deploy\Domain\Event\DeployFailed;
use Vortos\Deploy\Domain\Event\DeployRefused;
use Vortos\Deploy\Domain\Event\DeploySucceeded;
use Vortos\Deploy\Domain\Event\RolledBack;
use Vortos\Domain\Event\EventEnvelope;

/**
 * Turns every deploy/rollback decision into a {@see DeployMarker} and emits it
 * (Block 16, §3.2). Plugs into the same Deploy-declared seam as
 * {@see \Vortos\Observability\Audit\DeployAuditProjector} — autoconfigured as a
 * {@see DeployAuditSinkInterface} implementation, so Deploy never depends on
 * Observability.
 */
final class DeployMarkerSink implements DeployAuditSinkInterface
{
    public function __construct(
        private readonly MarkerEmitterInterface $emitter,
    ) {
    }

    public function handle(EventEnvelope $envelope): void
    {
        $event = $envelope->payload;

        $marker = match (true) {
            $event instanceof DeployAttempted => new DeployMarker(
                env: $event->env,
                kind: 'deploy',
                buildId: $event->buildId,
                gitSha: $event->gitSha,
                imageDigest: $event->imageDigest,
                schemaFingerprintId: $event->schemaFingerprintId,
                title: sprintf('Deploy attempted: %s', $event->env),
                tags: ['attempted'],
                at: $envelope->occurredAt,
            ),
            $event instanceof DeploySucceeded => new DeployMarker(
                env: $event->env,
                kind: 'deploy',
                buildId: $event->buildId,
                gitSha: $event->gitSha,
                imageDigest: $event->imageDigest,
                schemaFingerprintId: $event->schemaFingerprintId,
                title: sprintf('Deployed: %s', $event->env),
                tags: ['succeeded'],
                at: $envelope->occurredAt,
            ),
            $event instanceof DeployRefused => new DeployMarker(
                env: $event->env,
                kind: 'deploy',
                buildId: $event->buildId,
                gitSha: $event->gitSha,
                imageDigest: $event->imageDigest,
                schemaFingerprintId: $event->schemaFingerprintId,
                title: sprintf('Deploy refused: %s', $event->env),
                tags: ['refused'],
                at: $envelope->occurredAt,
            ),
            $event instanceof DeployFailed => new DeployMarker(
                env: $event->env,
                kind: 'deploy',
                buildId: $event->buildId,
                gitSha: $event->gitSha,
                imageDigest: $event->imageDigest,
                schemaFingerprintId: $event->schemaFingerprintId,
                title: sprintf('Deploy failed: %s', $event->env),
                tags: ['failed'],
                at: $envelope->occurredAt,
            ),
            $event instanceof RolledBack => new DeployMarker(
                env: $event->env,
                kind: 'rollback',
                buildId: $event->toBuildId,
                gitSha: '',
                imageDigest: '',
                schemaFingerprintId: '',
                title: sprintf('Rolled back: %s', $event->env),
                tags: ['rolled-back'],
                at: $envelope->occurredAt,
            ),
            default => null,
        };

        if ($marker !== null) {
            $this->emitter->emit($marker);
        }
    }
}
