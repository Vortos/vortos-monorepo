<?php

declare(strict_types=1);

namespace Vortos\AwsSes\DependencyInjection;

final class AwsSesWebhookConfig
{
    private bool $enabled = true;
    private string $routePath = '/webhooks/aws/ses';

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

    /** @internal */
    public function toArray(): array
    {
        return [
            'enabled'    => $this->enabled,
            'route_path' => $this->routePath,
        ];
    }
}
