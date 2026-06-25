<?php

declare(strict_types=1);

namespace Vortos\Deploy\Compose;

final readonly class ColorEndpoint
{
    public function __construct(
        public string $host,
        public int $port,
    ) {
        if ($host === '') {
            throw new \InvalidArgumentException('Color endpoint host must not be empty.');
        }

        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(sprintf('Color endpoint port must be 1-65535, got %d.', $port));
        }
    }

    public function toUrl(string $path = '/'): string
    {
        return sprintf('http://%s:%d%s', $this->host, $this->port, $path);
    }
}
