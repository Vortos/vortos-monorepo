<?php

declare(strict_types=1);

namespace Vortos\Security\Event;

use Vortos\Security\Contract\SecurityEventInterface;

final readonly class SignatureInvalidEvent implements SecurityEventInterface
{
    public function __construct(
        public string $ip,
        public string $path,
        public string $signatureHeader,
    ) {}

    public function eventName(): string
    {
        return 'security.signature_invalid';
    }

    public function context(): array
    {
        return ['ip' => $this->ip, 'path' => $this->path, 'header' => $this->signatureHeader];
    }
}
