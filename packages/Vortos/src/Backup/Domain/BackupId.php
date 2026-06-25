<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A backup's immutable, lexicographically-sortable identity.
 *
 * Shape: `{utc:Ymd\THis}-{engine}-{kind}-{rand10}` — e.g.
 * `20260623T141005-postgres-logical_full-9f3a2b7c10`. The UTC timestamp prefix makes
 * ids sort chronologically (so a plain `ORDER BY build id` and a store key listing
 * are both time-ordered), and the random suffix guarantees uniqueness within the
 * same second.
 */
final readonly class BackupId
{
    private const PATTERN = '/^\d{8}T\d{6}-[a-z0-9_]+-[a-z0-9_]+-[0-9a-f]{10}$/';

    private function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("Malformed backup id: '{$value}'.");
        }
    }

    public static function generate(DatabaseEngine $engine, BackupKind $kind, DateTimeImmutable $createdAt): self
    {
        $utc = $createdAt->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis');
        $rand = bin2hex(random_bytes(5));

        return new self(sprintf('%s-%s-%s-%s', $utc, $engine->value, $kind->value, $rand));
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
