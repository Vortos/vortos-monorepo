<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle;

final readonly class IacApplyResult
{
    /**
     * @param array<string, mixed> $outputs
     */
    public function __construct(
        public int $applied,
        public int $failed,
        public int $durationMs,
        public string $outputsDigest,
        public array $outputs = [],
    ) {
        if ($applied < 0 || $failed < 0 || $durationMs < 0) {
            throw new \InvalidArgumentException('Apply result counts must be non-negative.');
        }
    }

    public function isSuccess(): bool
    {
        return $this->failed === 0;
    }
}
