<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest;

final class ChangeRequest
{
    public function __construct(
        private string $id,
        private string $flagName,
        private string $projectId,
        private string $environment,
        private ChangeType $changeType,
        private array $payload,
        private string $reason,
        private string $requestedBy,
        private \DateTimeImmutable $requestedAt,
        private ChangeRequestStatus $status,
        private int $requiredApprovals,
        private array $approvals,
        private array $rejections,
        private ?\DateTimeImmutable $applyAt,
        private \DateTimeImmutable $expiresAt,
        private ?\DateTimeImmutable $appliedAt = null,
        private ?string $appliedBy = null,
    ) {}

    public static function create(
        string $id,
        string $flagName,
        string $projectId,
        string $environment,
        ChangeType $changeType,
        array $payload,
        string $reason,
        string $requestedBy,
        \DateTimeImmutable $requestedAt,
        int $requiredApprovals,
        \DateTimeImmutable $expiresAt,
        ?\DateTimeImmutable $applyAt = null,
    ): self {
        if (strlen(trim($reason)) < 10) {
            throw new \DomainException('Change request reason must be at least 10 characters.');
        }

        return new self(
            id:                $id,
            flagName:          $flagName,
            projectId:         $projectId,
            environment:       $environment,
            changeType:        $changeType,
            payload:           $payload,
            reason:            $reason,
            requestedBy:       $requestedBy,
            requestedAt:       $requestedAt,
            status:            ChangeRequestStatus::Pending,
            requiredApprovals: $requiredApprovals,
            approvals:         [],
            rejections:        [],
            applyAt:           $applyAt,
            expiresAt:         $expiresAt,
        );
    }

    public function addApproval(string $actorId, string $reason, \DateTimeImmutable $at): void
    {
        if ($actorId === $this->requestedBy) {
            throw new \DomainException('Requestor cannot approve their own change request (4-eyes principle).');
        }

        $this->assertNotAlreadyVoted($actorId);
        $this->assertCanVote();

        $this->approvals[] = new Approval($actorId, $reason, $at);

        if (count($this->approvals) >= $this->requiredApprovals) {
            $this->transitionTo(ChangeRequestStatus::Approved);
        }
    }

    public function addRejection(string $actorId, string $reason, \DateTimeImmutable $at): void
    {
        $this->assertNotAlreadyVoted($actorId);
        $this->assertCanVote();

        $this->rejections[] = new Rejection($actorId, $reason, $at);
        $this->transitionTo(ChangeRequestStatus::Rejected);
    }

    public function markApplied(string $appliedBy, \DateTimeImmutable $at): void
    {
        if ($this->status !== ChangeRequestStatus::Approved) {
            throw new \DomainException(
                sprintf('Cannot apply change request in status "%s" — must be Approved.', $this->status->value),
            );
        }

        $this->transitionTo(ChangeRequestStatus::Applied);
        $this->appliedAt = $at;
        $this->appliedBy = $appliedBy;
    }

    public function cancel(string $actorId): void
    {
        if (!$this->status->canTransitionTo(ChangeRequestStatus::Cancelled)) {
            throw new \DomainException(
                sprintf('Cannot cancel change request in status "%s".', $this->status->value),
            );
        }

        $this->transitionTo(ChangeRequestStatus::Cancelled);
    }

    public function markExpired(): void
    {
        if (!$this->status->canTransitionTo(ChangeRequestStatus::Expired)) {
            throw new \DomainException(
                sprintf('Cannot expire change request in status "%s".', $this->status->value),
            );
        }

        $this->transitionTo(ChangeRequestStatus::Expired);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function flagName(): string
    {
        return $this->flagName;
    }

    public function projectId(): string
    {
        return $this->projectId;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function changeType(): ChangeType
    {
        return $this->changeType;
    }

    public function payload(): array
    {
        return $this->payload;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function requestedBy(): string
    {
        return $this->requestedBy;
    }

    public function requestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function status(): ChangeRequestStatus
    {
        return $this->status;
    }

    public function requiredApprovals(): int
    {
        return $this->requiredApprovals;
    }

    /** @return Approval[] */
    public function approvals(): array
    {
        return $this->approvals;
    }

    /** @return Rejection[] */
    public function rejections(): array
    {
        return $this->rejections;
    }

    public function applyAt(): ?\DateTimeImmutable
    {
        return $this->applyAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function appliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function appliedBy(): ?string
    {
        return $this->appliedBy;
    }

    private function assertCanVote(): void
    {
        if ($this->status !== ChangeRequestStatus::Pending) {
            throw new \DomainException(
                sprintf('Cannot vote on change request in status "%s".', $this->status->value),
            );
        }
    }

    private function assertNotAlreadyVoted(string $actorId): void
    {
        foreach ($this->approvals as $approval) {
            if ($approval->actorId === $actorId) {
                throw new \DomainException(sprintf('Actor "%s" has already voted on this change request.', $actorId));
            }
        }

        foreach ($this->rejections as $rejection) {
            if ($rejection->actorId === $actorId) {
                throw new \DomainException(sprintf('Actor "%s" has already voted on this change request.', $actorId));
            }
        }
    }

    private function transitionTo(ChangeRequestStatus $next): void
    {
        $this->status = $next;
    }
}
