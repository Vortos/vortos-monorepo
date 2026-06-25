<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Caddy;

final class DrainResult
{
    private function __construct(
        public readonly int $drained,
        public readonly int $forciblyClosed,
        public readonly bool $metricsReachable,
    ) {}

    public static function clean(int $drained): self
    {
        return new self($drained, 0, true);
    }

    public static function partial(int $drained, int $forciblyClosed): self
    {
        return new self($drained, $forciblyClosed, true);
    }

    public static function unknown(): self
    {
        return new self(0, 0, false);
    }
}
