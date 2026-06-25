<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle;

final readonly class IacDestroyResult
{
    public function __construct(
        public int $destroyed,
        public int $failed,
        public int $durationMs,
    ) {
        if ($destroyed < 0 || $failed < 0 || $durationMs < 0) {
            throw new \InvalidArgumentException('Destroy result counts must be non-negative.');
        }
    }

    public function isSuccess(): bool
    {
        return $this->failed === 0;
    }
}
