<?php

declare(strict_types=1);

namespace Vortos\Security\Event;

use Vortos\Security\Contract\SecurityEventInterface;

final readonly class SuspiciousRequestEvent implements SecurityEventInterface
{
    public function __construct(
        public string $ip,
        public string $path,
        public string $reason,
        public array  $extra = [],
    ) {}

    public function eventName(): string
    {
        return 'security.suspicious_request';
    }

    public function context(): array
    {
        return array_merge(
            ['ip' => $this->ip, 'path' => $this->path, 'reason' => $this->reason],
            $this->extra,
        );
    }
}
