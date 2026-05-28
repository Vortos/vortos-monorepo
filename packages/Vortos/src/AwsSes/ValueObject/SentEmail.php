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
}
