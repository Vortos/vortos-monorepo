<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class PromoteObjectRequest
{
    public function __construct(
        private readonly ObjectKey $temporaryKey,
        private readonly ObjectKey $permanentKey,
        private readonly string $temporaryPrefix = 'tmp',
        private readonly bool $deleteTemporarySource = true,
    ) {
        $prefix = trim($temporaryPrefix, '/');
        if ($prefix === '') {
            throw new \InvalidArgumentException('Temporary prefix cannot be empty.');
        }

        if (!str_starts_with($temporaryKey->value(), $prefix . '/')) {
            throw new \InvalidArgumentException(sprintf('Source key must be under the "%s/" temporary prefix.', $prefix));
        }

        if (str_starts_with($permanentKey->value(), $prefix . '/')) {
            throw new \InvalidArgumentException(sprintf('Permanent key cannot remain under the "%s/" temporary prefix.', $prefix));
        }
    }

    public static function fromKeys(
        ObjectKey|string $temporaryKey,
        ObjectKey|string $permanentKey,
        string $temporaryPrefix = 'tmp',
    ): self {
        return new self(ObjectKey::from($temporaryKey), ObjectKey::from($permanentKey), $temporaryPrefix);
    }

    public function temporaryKey(): ObjectKey
    {
        return $this->temporaryKey;
    }

    public function permanentKey(): ObjectKey
    {
        return $this->permanentKey;
    }

    public function temporaryPrefix(): string
    {
        return trim($this->temporaryPrefix, '/');
    }

    public function deleteTemporarySource(): bool
    {
        return $this->deleteTemporarySource;
    }
}
