<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Suppression;

use DateTimeImmutable;
use Vortos\AwsSes\ValueObject\EmailAddress;

final class SuppressionEntry
{
    public function __construct(
        private readonly string $id,
        private readonly EmailAddress $address,
        private readonly SuppressionReason $reason,
        private readonly DateTimeImmutable $suppressedAt,
        private readonly DateTimeImmutable $createdAt,
    ) {}

    public function id(): string { return $this->id; }

    public function address(): EmailAddress { return $this->address; }

    public function reason(): SuppressionReason { return $this->reason; }

    public function suppressedAt(): DateTimeImmutable { return $this->suppressedAt; }

    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
}
