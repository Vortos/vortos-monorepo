<?php

declare(strict_types=1);

namespace Vortos\AwsSes\DependencyInjection;

final class AwsSesWebhookConfig
{
    private bool $enabled = true;
    private string $routePath = '/webhooks/aws/ses';
    private int $maxBodyBytes = 65536;

    public function enabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function routePath(string $path): static
    {
        $this->routePath = $path;
        return $this;
    }

    public function maxBodyBytes(int $bytes): static
    {
        $this->maxBodyBytes = $bytes;
        return $this;
    }

    /** @internal */
    public function toArray(): array
    {
        return [
            'enabled'        => $this->enabled,
            'route_path'     => $this->routePath,
            'max_body_bytes' => $this->maxBodyBytes,
        ];
    }
}
