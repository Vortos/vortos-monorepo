<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover;

final readonly class CutoverResult
{
    public function __construct(
        public bool $succeeded,
        public bool $reverted,
        public int $drainedConnections,
        public int $forciblyClosed,
        public int $durationMs,
        public bool $verifiedLiveUpstream,
        public string $detail = '',
    ) {}

    public function assertZeroDrops(): void
    {
        if ($this->forciblyClosed > 0) {
            throw new \RuntimeException(sprintf(
                'Cutover had %d forcibly closed connections (expected 0).',
                $this->forciblyClosed,
            ));
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'succeeded' => $this->succeeded,
            'reverted' => $this->reverted,
            'drained_connections' => $this->drainedConnections,
            'forcibly_closed' => $this->forciblyClosed,
            'duration_ms' => $this->durationMs,
            'verified_live_upstream' => $this->verifiedLiveUpstream,
            'detail' => $this->detail,
        ];
    }
}
