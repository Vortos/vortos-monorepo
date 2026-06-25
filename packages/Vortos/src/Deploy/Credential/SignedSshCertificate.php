<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential;

use Vortos\Secrets\Value\SecretValue;

final readonly class SignedSshCertificate
{
    /**
     * @param list<string> $principals
     */
    public function __construct(
        public SecretValue $certBlob,
        public \DateTimeImmutable $validBefore,
        public array $principals,
        public string $serial,
    ) {
        if ($principals === []) {
            throw new \InvalidArgumentException('SSH certificate must have at least one principal.');
        }

        if ($serial === '') {
            throw new \InvalidArgumentException('SSH certificate serial must not be empty.');
        }
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->validBefore;
    }

    public function ttlSeconds(\DateTimeImmutable $now): int
    {
        $remaining = $this->validBefore->getTimestamp() - $now->getTimestamp();

        return max(0, $remaining);
    }
}
