<?php

declare(strict_types=1);

namespace Vortos\Deploy\Audit;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Vortos\Domain\Event\DomainEventLedger;
use Vortos\Domain\Event\EventEnvelope;

/**
 * Optional collaborator for {@see \Vortos\Deploy\Runner\DeployRunner} and
 * {@see \Vortos\Deploy\Runner\RollbackRunner} (Block 16, §3.1). Records a
 * {@see DeployAuditAggregate} event for every deploy/rollback decision — including
 * refused and failed attempts — and forwards it to every registered
 * {@see DeployAuditSinkInterface}.
 *
 * Always safe to call: a sink failure is caught and logged, never propagated, so
 * an audit-backend outage cannot fail or block a deploy. The recorder itself is
 * registered unconditionally by {@see \Vortos\Deploy\DependencyInjection\DeployExtension}
 * (zero sinks ⇒ a pure no-op), so DeployRunner/RollbackRunner always have a
 * non-null collaborator — no special-casing at the call site.
 */
final class DeployAuditRecorder
{
    /**
     * @param iterable<DeployAuditSinkInterface> $sinks
     */
    public function __construct(
        private readonly iterable $sinks,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function attempted(
        string $env,
        string $actorId,
        ActorIdentitySource $actorIdentitySource,
        string $buildId,
        string $gitSha,
        string $imageDigest,
        string $schemaFingerprintId,
        ?string $reason,
    ): void {
        $this->record(static fn () => DeployAuditAggregate::attempted(
            $env,
            $actorId,
            $actorIdentitySource,
            $buildId,
            $gitSha,
            $imageDigest,
            $schemaFingerprintId,
            $reason,
        ));
    }

    public function succeeded(
        string $env,
        string $actorId,
        ActorIdentitySource $actorIdentitySource,
        string $buildId,
        string $gitSha,
        string $imageDigest,
        string $schemaFingerprintId,
        ?string $reason,
        string $targetStatusSummary,
    ): void {
        $this->record(static fn () => DeployAuditAggregate::succeeded(
            $env,
            $actorId,
            $actorIdentitySource,
            $buildId,
            $gitSha,
            $imageDigest,
            $schemaFingerprintId,
            $reason,
            $targetStatusSummary,
        ));
    }

    /**
     * @param list<string> $failedCheckIds
     */
    public function refused(
        string $env,
        string $actorId,
        ActorIdentitySource $actorIdentitySource,
        string $buildId,
        string $gitSha,
        string $imageDigest,
        string $schemaFingerprintId,
        ?string $reason,
        array $failedCheckIds,
    ): void {
        $this->record(static fn () => DeployAuditAggregate::refused(
            $env,
            $actorId,
            $actorIdentitySource,
            $buildId,
            $gitSha,
            $imageDigest,
            $schemaFingerprintId,
            $reason,
            $failedCheckIds,
        ));
    }

    public function failed(
        string $env,
        string $actorId,
        ActorIdentitySource $actorIdentitySource,
        string $buildId,
        string $gitSha,
        string $imageDigest,
        string $schemaFingerprintId,
        ?string $reason,
        string $errorClass,
        string $errorMessage,
    ): void {
        $this->record(static fn () => DeployAuditAggregate::failed(
            $env,
            $actorId,
            $actorIdentitySource,
            $buildId,
            $gitSha,
            $imageDigest,
            $schemaFingerprintId,
            $reason,
            $errorClass,
            $errorMessage,
        ));
    }

    public function rolledBack(
        string $env,
        string $actorId,
        ActorIdentitySource $actorIdentitySource,
        string $fromBuildId,
        string $toBuildId,
        ?string $reason,
    ): void {
        $this->record(static fn () => DeployAuditAggregate::rolledBack(
            $env,
            $actorId,
            $actorIdentitySource,
            $fromBuildId,
            $toBuildId,
            $reason,
        ));
    }

    /**
     * @param callable(): DeployAuditAggregate $build
     */
    private function record(callable $build): void
    {
        $ledger = DomainEventLedger::instance();
        $isRoot = $ledger->open();

        try {
            $build();

            if ($isRoot) {
                while ($ledger->hasPending()) {
                    foreach ($ledger->drain() as $envelope) {
                        $this->dispatchToSinks($envelope);
                    }
                }
            }
        } finally {
            $ledger->close();
        }
    }

    private function dispatchToSinks(EventEnvelope $envelope): void
    {
        foreach ($this->sinks as $sink) {
            try {
                $sink->handle($envelope);
            } catch (Throwable $e) {
                $this->logger->error('Deploy audit sink failed to handle envelope', [
                    'sink' => $sink::class,
                    'event' => $envelope->payloadType,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}
