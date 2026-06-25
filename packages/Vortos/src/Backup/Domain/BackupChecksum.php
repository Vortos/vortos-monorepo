<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain;

use InvalidArgumentException;

/**
 * A content checksum over a backup's bytes, used to prove integrity at creation and
 * at restore time.
 *
 * Comparisons are constant-time ({@see hash_equals}) — a checksum compare is a
 * security-relevant operation (it gates whether a backup is trusted), so it must not
 * leak timing.
 */
final readonly class BackupChecksum
{
    public const DEFAULT_ALGORITHM = 'sha256';

    private function __construct(
        public string $algorithm,
        public string $hex,
    ) {
        if ($algorithm === '') {
            throw new InvalidArgumentException('Checksum algorithm must be non-empty.');
        }
        if (preg_match('/^[0-9a-f]+$/', $hex) !== 1) {
            throw new InvalidArgumentException('Checksum must be lowercase hex.');
        }
    }

    public static function sha256(string $hex): self
    {
        return new self(self::DEFAULT_ALGORITHM, strtolower($hex));
    }

    public static function of(string $algorithm, string $hex): self
    {
        return new self($algorithm, strtolower($hex));
    }

    /** Compute a checksum over an in-memory string (small inputs / tests only). */
    public static function ofString(string $data, string $algorithm = self::DEFAULT_ALGORITHM): self
    {
        return new self($algorithm, hash($algorithm, $data));
    }

    /**
     * Compute a checksum by streaming a resource end-to-end — bounded memory,
     * never loading the whole artifact. The stream is left at EOF.
     *
     * @param resource $stream
     */
    public static function ofStream($stream, string $algorithm = self::DEFAULT_ALGORITHM): self
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('ofStream() expects an open resource.');
        }

        $ctx = hash_init($algorithm);
        while (!feof($stream)) {
            $chunk = fread($stream, 1 << 20);
            if ($chunk === false) {
                throw new InvalidArgumentException('Failed reading stream while hashing.');
            }
            hash_update($ctx, $chunk);
        }

        return new self($algorithm, hash_final($ctx));
    }

    public function equals(self $other): bool
    {
        return $this->algorithm === $other->algorithm && hash_equals($this->hex, $other->hex);
    }

    public function __toString(): string
    {
        return $this->algorithm . ':' . $this->hex;
    }
}
