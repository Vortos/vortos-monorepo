<?php

declare(strict_types=1);

namespace Vortos\Health\Startup;

final class StartupGate
{
    private bool $started = false;

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function markStarted(): void
    {
        $this->started = true;
    }

    public function reset(): void
    {
        $this->started = false;
    }
}
