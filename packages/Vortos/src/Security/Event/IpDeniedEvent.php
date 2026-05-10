<?php

declare(strict_types=1);

namespace Vortos\Security\Event;

use Vortos\Security\Contract\SecurityEventInterface;

final readonly class IpDeniedEvent implements SecurityEventInterface
{
    public function __construct(
        public string $ip,
        public string $path,
    ) {}

    public function eventName(): string
    {
        return 'security.ip_denied';
    }

    public function context(): array
    {
        return ['ip' => $this->ip, 'path' => $this->path];
    }
}
