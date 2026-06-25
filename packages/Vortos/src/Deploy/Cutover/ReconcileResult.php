<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover;

final readonly class ReconcileResult
{
    public function __construct(
        public bool $inSync,
        public bool $drifted = false,
        public bool $corrected = false,
        public bool $skippedRateLimited = false,
        public string $detail = '',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'in_sync' => $this->inSync,
            'drifted' => $this->drifted,
            'corrected' => $this->corrected,
            'skipped_rate_limited' => $this->skippedRateLimited,
            'detail' => $this->detail,
        ];
    }
}
