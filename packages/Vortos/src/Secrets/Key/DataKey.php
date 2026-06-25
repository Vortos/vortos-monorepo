<?php

declare(strict_types=1);

namespace Vortos\Secrets\Key;

use InvalidArgumentException;
use Vortos\Secrets\Value\SecretValue;

/**
 * A raw data-encryption key (DEK) — the symmetric key that encrypts a secret's
 * payload. Opaque to callers; held inside a {@see SecretValue} so the raw key
 * bytes are never logged/dumped by accident. Only a {@see KeyProviderInterface}
 * wraps/unwraps it for off-host custody.
 */
final readonly class DataKey
{
    private function __construct(private SecretValue $bytes) {}

    public static function fromRaw(string $rawBytes): self
    {
        if ($rawBytes === '') {
            throw new InvalidArgumentException('DataKey raw bytes must not be empty.');
        }

        return new self(SecretValue::fromString($rawBytes));
    }

    /**
     * The raw key bytes, for {@see \Vortos\Secrets\Crypto\EnvelopeCipher} only.
     * Deliberately named distinctly from a generic getter so every call site is a
     * visible, auditable boundary crossing — mirrors {@see SecretValue::reveal()}.
     */
    public function revealForEncryption(): string
    {
        return $this->bytes->reveal();
    }

    public function wipe(): void
    {
        $this->bytes->wipe();
    }
}
