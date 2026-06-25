<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\ReconcileResult;

final class ReconcileResultTest extends TestCase
{
    public function test_in_sync_result(): void
    {
        $result = new ReconcileResult(inSync: true, detail: 'all good');

        $this->assertTrue($result->inSync);
        $this->assertFalse($result->drifted);
        $this->assertFalse($result->corrected);
        $this->assertFalse($result->skippedRateLimited);
    }

    public function test_drift_corrected_result(): void
    {
        $result = new ReconcileResult(
            inSync: false,
            drifted: true,
            corrected: true,
            detail: 'fixed',
        );

        $this->assertFalse($result->inSync);
        $this->assertTrue($result->drifted);
        $this->assertTrue($result->corrected);
    }

    public function test_rate_limited_result(): void
    {
        $result = new ReconcileResult(
            inSync: false,
            drifted: true,
            corrected: false,
            skippedRateLimited: true,
        );

        $this->assertTrue($result->skippedRateLimited);
        $this->assertFalse($result->corrected);
    }

    public function test_to_array(): void
    {
        $result = new ReconcileResult(
            inSync: false,
            drifted: true,
            corrected: true,
            detail: 'reconciled',
        );

        $arr = $result->toArray();
        $this->assertFalse($arr['in_sync']);
        $this->assertTrue($arr['drifted']);
        $this->assertTrue($arr['corrected']);
        $this->assertSame('reconciled', $arr['detail']);
    }
}
