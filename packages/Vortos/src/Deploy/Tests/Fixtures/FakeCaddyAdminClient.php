<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\Exception\CutoverFailedException;

final class FakeCaddyAdminClient
{
    /** @var array<string, mixed> */
    private array $currentConfig = [];
    private bool $shouldFail = false;
    private bool $unreachable = false;
    private bool $metricsUnreachable = false;
    private int $activeRequests = 0;

    /** @var list<array<string, mixed>> */
    private array $loadHistory = [];

    /** @param array<string, mixed> $config */
    public function load(array $config): void
    {
        if ($this->unreachable) {
            throw CutoverFailedException::adminUnreachable('fake: admin unreachable');
        }

        if ($this->shouldFail) {
            throw CutoverFailedException::reloadFailed('fake: reload failed');
        }

        $this->loadHistory[] = $config;
        $this->currentConfig = $config;
    }

    /** @return array<string, mixed> */
    public function currentConfig(): array
    {
        if ($this->unreachable) {
            throw CutoverFailedException::adminUnreachable('fake: admin unreachable');
        }

        return $this->currentConfig;
    }

    public function activeRequests(): int
    {
        if ($this->metricsUnreachable) {
            throw CutoverFailedException::metricsUnreachable('fake: metrics unreachable');
        }

        return $this->activeRequests;
    }

    public function setFail(bool $fail): void
    {
        $this->shouldFail = $fail;
    }

    public function setUnreachable(bool $unreachable): void
    {
        $this->unreachable = $unreachable;
    }

    public function setMetricsUnreachable(bool $unreachable): void
    {
        $this->metricsUnreachable = $unreachable;
    }

    public function setActiveRequests(int $count): void
    {
        $this->activeRequests = $count;
    }

    /** @param array<string, mixed> $config */
    public function setCurrentConfig(array $config): void
    {
        $this->currentConfig = $config;
    }

    /** @return list<array<string, mixed>> */
    public function loadHistory(): array
    {
        return $this->loadHistory;
    }
}
