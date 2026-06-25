<?php

declare(strict_types=1);

namespace Vortos\Health\Uptime;

use Vortos\OpsKit\Driver\DriverInterface;
use Vortos\OpsKit\Driver\Exception\UnsupportedCapabilityException;

/**
 * Control-plane-only port over an off-host external synthetic prober (§4). The
 * actual probing happens on the provider's plane — that is what makes this detector
 * independent of the app host. The app declares a journey and reads the verdict; it
 * never runs the synthetic check itself (the independence architecture test enforces
 * this by construction — see DetectorIndependenceTest).
 */
interface UptimeMonitorInterface extends DriverInterface
{
    /**
     * Idempotent: declare/update the monitor for a journey. Returns the provider's
     * monitor id. Re-running with an unchanged descriptor must not mutate provider
     * state (the idempotency contract the sync command's dry-run-default relies on).
     *
     * @throws UnsupportedCapabilityException when the descriptor requires a
     *         capability (multi-region, response-time SLO) this driver does not
     *         declare.
     */
    public function sync(MonitorDescriptor $descriptor): string;

    /**
     * Read current off-host status. Bounded (hard connect/total timeout) and never
     * throws into a scheduler loop — a slow/broken provider returns
     * {@see MonitorState::Unknown}.
     */
    public function status(string $monitorId): MonitorStatus;

    /**
     * @param list<string> $monitorIds
     * @return list<MonitorStatus> one entry per input id, same order, O(1) provider
     *         round-trips regardless of monitor count
     */
    public function statuses(array $monitorIds): array;
}
