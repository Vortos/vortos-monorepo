<?php

declare(strict_types=1);

namespace Vortos\Security\Event;

use Vortos\Security\Contract\SecurityEventInterface;

final readonly class CsrfViolationEvent implements SecurityEventInterface
{
    public function __construct(
        public string $ip,
        public string $path,
        public string $method,
    ) {}

    public function eventName(): string
    {
        return 'security.csrf_violation';
    }

    public function context(): array
    {
        return ['ip' => $this->ip, 'path' => $this->path, 'method' => $this->method];
    }
}
