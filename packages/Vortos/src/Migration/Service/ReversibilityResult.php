<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

/** What down-verify found: every migration in the window round-tripped, or the first that did not. */
final class ReversibilityResult
{
    /** @param list<string> $verified */
    private function __construct(
        public readonly bool $ok,
        public readonly int $verifiedCount,
        public readonly array $verified,
        public readonly ?string $phase,
        public readonly ?string $version,
        public readonly ?string $reason,
    ) {}

    /** @param list<string> $verified */
    public static function passed(int $count, array $verified = []): self
    {
        return new self(true, $count, $verified, null, null, null);
    }

    public static function failed(string $phase, string $version, string $reason): self
    {
        return new self(false, 0, [], $phase, $version, $reason);
    }
}
