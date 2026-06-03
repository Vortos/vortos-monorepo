<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Outbox;

final readonly class OutboxEntry
{
    public function __construct(
        public readonly string $outboxId,
        public readonly OutboxStatus $status,
        public readonly ?string $awsMessageId,
        public readonly int $attemptCount,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $sentAt,
        public readonly ?string $lastError,
    ) {}

    public function isDelivered(): bool
    {
        return $this->status === OutboxStatus::Sent;
    }

    public function isPending(): bool
    {
        return $this->status === OutboxStatus::Pending || $this->status === OutboxStatus::Processing;
    }

    public function isDead(): bool
    {
        return $this->status === OutboxStatus::Dead;
    }
}
