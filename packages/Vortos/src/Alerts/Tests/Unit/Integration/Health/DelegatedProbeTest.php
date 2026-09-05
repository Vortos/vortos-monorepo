<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Integration\Health;

use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Integration\Health\RemoteProbeResult;

/**
 * A probe answer that crossed a network is not a local verdict, and must not be treated as one.
 *
 * The delegation exists because alert evaluation runs on one node while a probe can only be answered
 * by the node that owns the dependency. On this deployment the backup sidecar evaluates every alert
 * and holds object-store credentials deliberately denied the application's bucket — so its own probe
 * returned 403 and `object-store-unreachable` paged hourly for over a day, while the application
 * reported the same store healthy throughout.
 */
final class DelegatedProbeTest extends TestCase
{
    public function testOnlyAnExplicitFailIsAFailure(): void
    {
        self::assertTrue((new RemoteProbeResult('object_store', 'fail'))->isFailing());
        self::assertTrue((new RemoteProbeResult('object_store', 'FAIL'))->isFailing());
    }

    /**
     * A Monitoring probe warns about trajectory — a certificate three weeks out, a bill trending
     * wrong. A `health_probe_failing` rule asks whether the dependency is DOWN, so a warning must
     * not page for something the probe deliberately chose not to escalate.
     */
    public function testAWarningIsNotAFailure(): void
    {
        self::assertFalse((new RemoteProbeResult('cert', 'warn'))->isFailing());
        self::assertFalse((new RemoteProbeResult('object_store', 'pass'))->isFailing());
    }

    /**
     * The important one. A body this reader could not parse, or a status word from a future version,
     * must never become a page for a dependency nobody said was broken — that is the exact
     * "I cannot see it" / "it is broken" conflation the delegation was built to remove.
     */
    public function testAnUnrecognisedStatusIsNotAFailure(): void
    {
        foreach (['unknown', '', 'degraded', 'error', 'FAILED'] as $status) {
            self::assertFalse(
                (new RemoteProbeResult('object_store', $status))->isFailing(),
                "status '{$status}' must not be read as a failure",
            );
        }
    }
}
