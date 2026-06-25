<?php

declare(strict_types=1);

namespace Vortos\Backup\Crypto;

use Vortos\Backup\Domain\Exception\BackupException;

final class EnvelopeFormatException extends BackupException
{
    public static function badMagic(string $actual): self
    {
        return new self(sprintf(
            'Invalid envelope magic: expected VBKP1, got %s.',
            bin2hex(substr($actual, 0, 6)),
        ));
    }

    public static function unknownVersion(int $version): self
    {
        return new self(sprintf('Unknown envelope format version: %d.', $version));
    }

    public static function truncated(string $field): self
    {
        return new self(sprintf('Envelope header truncated while reading %s.', $field));
    }

    public static function emptyField(string $field): self
    {
        return new self(sprintf('Envelope header field "%s" must not be empty.', $field));
    }

    public static function unknownAeadId(int $id): self
    {
        return new self(sprintf('Unknown AEAD algorithm id: 0x%02x.', $id));
    }

    public static function headerTooLarge(int $size, int $max): self
    {
        return new self(sprintf(
            'Envelope header exceeds maximum size: %d bytes (max %d). This may indicate a crafted envelope.',
            $size,
            $max,
        ));
    }

    public static function fieldTooLarge(string $field, int $size, int $max): self
    {
        return new self(sprintf(
            'Envelope header field "%s" exceeds maximum size: %d bytes (max %d).',
            $field,
            $size,
            $max,
        ));
    }
}
