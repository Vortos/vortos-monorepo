<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential;

use Vortos\Secrets\Value\SecretValue;

final readonly class IssuedCredential
{
    public function __construct(
        public string $type,
        public SecretValue $material,
        public \DateTimeImmutable $expiresAt,
        public string $issuedFor = '',
    ) {
        if ($type === '') {
            throw new \InvalidArgumentException('Credential type must not be empty.');
        }
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
