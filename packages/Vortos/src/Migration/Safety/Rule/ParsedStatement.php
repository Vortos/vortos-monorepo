<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety\Rule;

final readonly class ParsedStatement
{
    public string $normalized;

    public function __construct(
        public string $raw,
        public int $index,
    ) {
        $this->normalized = strtoupper(trim($this->raw));
    }

    public function contains(string $pattern): bool
    {
        return (bool) preg_match('/' . $pattern . '/i', $this->raw);
    }

    public function matches(string $pattern): bool
    {
        return (bool) preg_match('/' . $pattern . '/i', $this->raw);
    }

    public function isDDL(): bool
    {
        return (bool) preg_match('/^\s*(CREATE|ALTER|DROP|RENAME|TRUNCATE)\b/i', $this->raw);
    }
}
