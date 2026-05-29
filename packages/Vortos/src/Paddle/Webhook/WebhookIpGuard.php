<?php

declare(strict_types=1);

namespace Vortos\Paddle\Webhook;

use Vortos\Paddle\Exception\WebhookIpException;

final class WebhookIpGuard
{
    public function __construct(
        private readonly bool $enabled,
        private readonly bool $allowSandboxIps,
    ) {}

    public function check(string $remoteAddr): void
    {
        if (!$this->enabled) {
            return;
        }

        $allowed = KnownPaddleIps::LIVE;

        if ($this->allowSandboxIps) {
            $allowed = array_merge($allowed, KnownPaddleIps::SANDBOX);
        }

        if (!in_array($remoteAddr, $allowed, strict: true)) {
            throw WebhookIpException::notAllowed($remoteAddr);
        }
    }
}
