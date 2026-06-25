<?php

declare(strict_types=1);

namespace Vortos\Health\Probe\Cert;

final readonly class CertInspectionResult
{
    private function __construct(
        public ?int $daysUntilExpiry,
        public ?string $errorCode,
    ) {}

    public static function ok(int $daysUntilExpiry): self
    {
        return new self($daysUntilExpiry, null);
    }

    public static function failure(string $errorCode): self
    {
        return new self(null, $errorCode);
    }

    public function isFailure(): bool
    {
        return $this->errorCode !== null;
    }
}
