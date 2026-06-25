<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Caddy;

use Vortos\Deploy\Exception\CutoverFailedException;

final class DrainObserver
{
    private const POLL_INTERVAL_MS = 200;

    public function __construct(
        private readonly CaddyAdminClient $adminClient,
    ) {}

    public function awaitDrain(int $deadlineSeconds): DrainResult
    {
        try {
            $initialRequests = $this->adminClient->activeRequests();
        } catch (CutoverFailedException) {
            return DrainResult::unknown();
        }

        $deadline = microtime(true) + $deadlineSeconds;

        while (microtime(true) < $deadline) {
            try {
                $active = $this->adminClient->activeRequests();
            } catch (CutoverFailedException) {
                return DrainResult::unknown();
            }

            if ($active === 0) {
                return DrainResult::clean($initialRequests);
            }

            usleep(self::POLL_INTERVAL_MS * 1000);
        }

        try {
            $remaining = $this->adminClient->activeRequests();
        } catch (CutoverFailedException) {
            return DrainResult::unknown();
        }

        return DrainResult::partial(
            max(0, $initialRequests - $remaining),
            $remaining,
        );
    }
}
