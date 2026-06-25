<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Api;

final readonly class JobStatus
{
    public function __construct(
        public bool $completed,
        public bool $failed,
        public string $message = '',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'completed' => $this->completed,
            'failed' => $this->failed,
            'message' => $this->message,
        ];
    }
}
