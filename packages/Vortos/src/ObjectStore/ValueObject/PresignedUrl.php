<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class PresignedUrl
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string $url,
        private readonly HttpMethod $method,
        private readonly \DateTimeImmutable $expiresAt,
        private readonly array $headers = [],
    ) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Presigned URL must be a valid URL.');
        }
    }

    public function url(): string
    {
        return $this->url;
    }

    public function method(): HttpMethod
    {
        return $this->method;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
