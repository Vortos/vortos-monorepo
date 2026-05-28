<?php

declare(strict_types=1);

namespace Vortos\AwsSes\DependencyInjection;

final class AwsSesClientConfig
{
    private ?string $endpointOverride = null;
    private float $httpTimeout = 2.0;
    private int $maxRetries = 3;

    public function endpointOverride(?string $url): static
    {
        $this->endpointOverride = $url;
        return $this;
    }

    public function httpTimeout(float $seconds): static
    {
        $this->httpTimeout = $seconds;
        return $this;
    }

    public function maxRetries(int $retries): static
    {
        $this->maxRetries = $retries;
        return $this;
    }

    /** @internal */
    public function toArray(): array
    {
        return [
            'endpoint_override' => $this->endpointOverride,
            'http_timeout'      => $this->httpTimeout,
            'max_retries'       => $this->maxRetries,
        ];
    }
}
