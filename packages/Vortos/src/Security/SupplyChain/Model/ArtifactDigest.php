<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Model;

final readonly class ArtifactDigest
{
    private const PATTERN = '/^sha256:[a-f0-9]{64}$/';

    public string $value;

    public function __construct(string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Artifact digest must match sha256:<64 lowercase hex>, got "%s".',
                $value,
            ));
        }

        $this->value = $value;
    }

    public function algorithm(): string
    {
        return 'sha256';
    }

    public function hex(): string
    {
        return substr($this->value, 7);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }
}
