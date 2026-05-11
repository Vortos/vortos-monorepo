<?php

declare(strict_types=1);

namespace Vortos\Messaging\Command;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ReplayTimestampRange
{
    public function __construct(
        public ?DateTimeImmutable $from,
        public ?DateTimeImmutable $to,
        private string $fromOption,
        private string $toOption,
    ) {
        if ($this->from !== null && $this->to !== null && $this->from > $this->to) {
            throw new InvalidArgumentException(sprintf('%s must be earlier than or equal to %s.', $this->fromOption, $this->toOption));
        }
    }

    public static function fromOptions(?string $from, ?string $to, string $fromOption, string $toOption): self
    {
        return new self(
            self::parse($from, $fromOption),
            self::parse($to, $toOption),
            $fromOption,
            $toOption,
        );
    }

    public static function formatSql(?DateTimeInterface $value): ?string
    {
        return $value?->format('Y-m-d H:i:s');
    }

    private static function parse(?string $value, string $option): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (!self::hasSupportedTimestampFormat($value)) {
            throw new InvalidArgumentException(sprintf(
                '%s must be a full timestamp, e.g. 2026-05-11T10:30:00Z, 2026-05-11T10:30:00+05:30, or 2026-05-11 10:30:00.',
                $option,
            ));
        }

        $parsed = date_parse($value);
        if (($parsed['warning_count'] ?? 0) > 0 || ($parsed['error_count'] ?? 0) > 0) {
            throw new InvalidArgumentException(sprintf(
                '%s must be a valid timestamp, e.g. 2026-05-11T10:30:00Z.',
                $option,
            ));
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(date_default_timezone_get()));
        } catch (\Throwable $e) {
            throw new InvalidArgumentException(sprintf(
                '%s must be a valid timestamp, e.g. 2026-05-11T10:30:00Z.',
                $option,
            ), previous: $e);
        }
    }

    private static function hasSupportedTimestampFormat(string $value): bool
    {
        return preg_match(
            '/^\d{4}-\d{2}-\d{2}(?:[ T])\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/',
            $value,
        ) === 1;
    }
}
