<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain\Exception;

/**
 * Raised when a stored backup fails verification at creation (checksum mismatch or
 * an unrecognised on-disk format) — a corrupt dump must fail loudly, never be
 * recorded as a usable backup.
 */
final class IntegrityException extends BackupException
{
    public static function checksumMismatch(string $key, string $expected, string $actual): self
    {
        return new self(sprintf(
            "Integrity check failed for '%s': checksum mismatch (expected %s, got %s).",
            $key,
            $expected,
            $actual,
        ));
    }

    public static function unrecognisedFormat(string $key, string $engine): self
    {
        return new self(sprintf(
            "Integrity check failed for '%s': stored bytes are not a recognised %s backup format (corrupt or truncated dump).",
            $key,
            $engine,
        ));
    }

    public static function unreadable(string $key): self
    {
        return new self(sprintf("Integrity check failed for '%s': stored object could not be read back.", $key));
    }

    public static function envelopeMalformed(string $detail): self
    {
        return new self(sprintf('Envelope format error: %s.', $detail));
    }

    public static function undecryptable(string $reason): self
    {
        return new self(sprintf('Backup undecryptable: %s.', $reason));
    }
}
