<?php

declare(strict_types=1);

namespace Vortos\AwsSes\ValueObject;

use DateTimeImmutable;

final class SentEmail
{
    public function __construct(
        private readonly string $messageId,
        private readonly DateTimeImmutable $sentAt,
        private readonly int $recipientCount,
        private readonly string $driver = 'ses',
        private readonly ?string $region = null,
    ) {}

    public function messageId(): string
    {
        return $this->messageId;
    }

    public function sentAt(): DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function recipientCount(): int
    {
        return $this->recipientCount;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function region(): ?string
    {
        return $this->region;
    }

    /**
     * Returns true when the email was written to the transactional outbox rather than
     * sent immediately. In this case messageId() is the outbox row UUID, not an AWS
     * MessageId — use EmailOutboxStoreInterface::findById() to retrieve the real AWS
     * MessageId after the relay worker has processed the row.
     */
    public function isQueued(): bool
    {
        return $this->driver === 'outbox';
    }
}
