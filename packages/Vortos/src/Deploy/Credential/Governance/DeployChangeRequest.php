<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential\Governance;

final readonly class DeployChangeRequest
{
    public function __construct(
        private string $id,
        private string $environment,
        private string $requestedBy,
        private string $approvedBy,
        private \DateTimeImmutable $approvedAt,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function requestedBy(): string
    {
        return $this->requestedBy;
    }

    public function approvedBy(): string
    {
        return $this->approvedBy;
    }

    public function approvedAt(): \DateTimeImmutable
    {
        return $this->approvedAt;
    }
}
