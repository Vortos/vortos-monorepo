<?php

declare(strict_types=1);

namespace Vortos\Deploy\Audit;

use Vortos\Deploy\Domain\Event\DeployAttempted;
use Vortos\Deploy\Domain\Event\DeployFailed;
use Vortos\Deploy\Domain\Event\DeployRefused;
use Vortos\Deploy\Domain\Event\DeploySucceeded;
use Vortos\Deploy\Domain\Event\RolledBack;
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Identity\AggregateId;

/**
 * The chokepoint for the deploy audit trail (Block 16, §11.3).
 *
 * Every state-changing deploy/rollback decision — attempted, succeeded, refused,
 * or failed — is recorded here as a past-tense domain event, exactly like every
 * other aggregate in the framework (see {@see \Vortos\FeatureFlags\Domain\Flag}).
 * One instance == one recorded decision; it is never reloaded from persistence —
 * {@see DeployAuditRecorder} constructs a fresh instance per call and drains its
 * events immediately.
 */
final class DeployAuditAggregate extends AggregateRoot
{
    private function __construct(
        private readonly DeployAuditId $id,
    ) {
    }

    public function getId(): AggregateId
    {
        return $this->id;
    }

    public static function attempted(
        string $env,
        string $actorId,
        ActorIdentitySource $actorIdentitySource,
        string $buildId,
        string $gitSha,
        string $imageDigest,
        string $schemaFingerprintId,
        ?string $reason,
    ): self {
        $aggregate = new self(DeployAuditId::generate());
        $aggregate->recordEvent(new DeployAttempted(
            env: $env,
            actorId: $actorId,
            actorIdentitySource: $actorIdentitySource->value,
            buildId: $buildId,
            gitSha: $gitSha,
            imageDigest: $imageDigest,
            schemaFingerprintId: $schemaFingerprintId,
            reason: $reason,
        ));

        return $aggregate;
    }

    public static function succeeded(
        string $env,
        string $actorId,
        ActorIdentitySource $actorIdentitySource,
        string $buildId,
        string $gitSha,
        string $imageDigest,
        string $schemaFingerprintId,
        ?string $reason,
        string $targetStatusSummary,
    ): self {
        $aggregate = new self(DeployAuditId::generate());
        $aggregate->recordEvent(new DeploySucceeded(
            env: $env,
            actorId: $actorId,
            actorIdentitySource: $actorIdentitySource->value,
            buildId: $buildId,
            gitSha: $gitSha,
            imageDigest: $imageDigest,
            schemaFingerprintId: $schemaFingerprintId,
            reason: $reason,
            targetStatusSummary: $targetStatusSummary,
        ));

        return $aggregate;
    }

    /**
     * @param list<string> $failedCheckIds
     */
    public static function refused(
        string $env,
        string $actorId,
        ActorIdentitySource $actorIdentitySource,
        string $buildId,
        string $gitSha,
        string $imageDigest,
        string $schemaFingerprintId,
        ?string $reason,
        array $failedCheckIds,
    ): self {
        $aggregate = new self(DeployAuditId::generate());
        $aggregate->recordEvent(new DeployRefused(
            env: $env,
            actorId: $actorId,
            actorIdentitySource: $actorIdentitySource->value,
            buildId: $buildId,
            gitSha: $gitSha,
            imageDigest: $imageDigest,
            schemaFingerprintId: $schemaFingerprintId,
            reason: $reason,
            failedCheckIds: $failedCheckIds,
        ));

        return $aggregate;
    }

    public static function failed(
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
    ): self {
        $aggregate = new self(DeployAuditId::generate());
        $aggregate->recordEvent(new DeployFailed(
            env: $env,
            actorId: $actorId,
            actorIdentitySource: $actorIdentitySource->value,
            buildId: $buildId,
            gitSha: $gitSha,
            imageDigest: $imageDigest,
            schemaFingerprintId: $schemaFingerprintId,
            reason: $reason,
            errorClass: $errorClass,
            errorMessage: $errorMessage,
        ));

        return $aggregate;
    }

    public static function rolledBack(
        string $env,
        string $actorId,
        ActorIdentitySource $actorIdentitySource,
        string $fromBuildId,
        string $toBuildId,
        ?string $reason,
    ): self {
        $aggregate = new self(DeployAuditId::generate());
        $aggregate->recordEvent(new RolledBack(
            env: $env,
            actorId: $actorId,
            actorIdentitySource: $actorIdentitySource->value,
            fromBuildId: $fromBuildId,
            toBuildId: $toBuildId,
            reason: $reason,
        ));

        return $aggregate;
    }
}
