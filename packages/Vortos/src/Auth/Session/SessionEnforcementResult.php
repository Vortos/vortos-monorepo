<?php
declare(strict_types=1);

namespace Vortos\Auth\Session;

final class SessionEnforcementResult
{
    /**
     * @param list<string> $evictedJtis
     */
    private function __construct(
        public readonly bool $rejected,
        public readonly array $evictedJtis,
    ) {}

    /**
     * @param list<string> $evictedJtis
     */
    public static function ok(array $evictedJtis = []): self
    {
        return new self(false, $evictedJtis);
    }

    public static function rejected(): self
    {
        return new self(true, []);
    }
}
