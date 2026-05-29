<?php

declare(strict_types=1);

namespace Vortos\Paddle\Exception;

final class WebhookIpException extends PaddleWebhookException
{
    public static function notAllowed(string $ip): self
    {
        return new self(sprintf('IP address %s is not in the Paddle allowlist.', $ip));
    }
}
