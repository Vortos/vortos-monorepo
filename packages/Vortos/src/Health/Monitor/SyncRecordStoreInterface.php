<?php

declare(strict_types=1);

namespace Vortos\Health\Monitor;

/**
 * Idempotency seam for `health:monitor:sync` (§6.4): the last applied payload hash
 * per (env, journey). Re-running with no change is a no-op (no provider write).
 */
interface SyncRecordStoreInterface
{
    public function lastHash(string $env, string $journeyKey): ?string;

    public function record(string $env, string $journeyKey, string $hash, string $monitorId): void;
}
