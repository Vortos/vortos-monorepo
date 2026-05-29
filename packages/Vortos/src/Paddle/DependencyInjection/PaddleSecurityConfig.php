<?php

declare(strict_types=1);

namespace Vortos\Paddle\DependencyInjection;

final class PaddleSecurityConfig
{
    private bool $enforceIpAllowlist  = false;
    private int  $replayWindowSeconds = 5;
    private bool $allowSandboxIps     = false;

    public function enforceIpAllowlist(bool $enforce): static
    {
        $this->enforceIpAllowlist = $enforce;
        return $this;
    }

    public function replayWindowSeconds(int $seconds): static
    {
        $this->replayWindowSeconds = $seconds;
        return $this;
    }

    public function allowSandboxIps(bool $allow): static
    {
        $this->allowSandboxIps = $allow;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'enforce_ip_allowlist'   => $this->enforceIpAllowlist,
            'replay_window_seconds'  => $this->replayWindowSeconds,
            'allow_sandbox_ips'      => $this->allowSandboxIps,
        ];
    }
}
