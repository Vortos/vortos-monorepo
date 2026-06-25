<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\DistinctId;
use Vortos\Analytics\Runtime\AnalyticsSpool;
use Vortos\Observability\Buffer\BoundedSpool;

final class AnalyticsSpoolTest extends TestCase
{
    public function test_enqueue_then_drain_round_trips_an_event(): void
    {
        $spool = $this->spool();
        $event = new AnalyticsEvent(new DistinctId('user-1'), 'signup', ['plan' => 'pro']);

        $this->assertTrue($spool->enqueue($event));
        $this->assertFalse($spool->isEmpty());

        $drained = $spool->drain(10);
        $this->assertCount(1, $drained);
        $this->assertSame('user-1', $drained[0]->distinctId->value);
        $this->assertSame('signup', $drained[0]->name);
        $this->assertSame(['plan' => 'pro'], $drained[0]->properties);
    }

    public function test_drain_preserves_fifo_order(): void
    {
        $spool = $this->spool();
        $spool->enqueue(new AnalyticsEvent(new DistinctId('u'), 'first'));
        $spool->enqueue(new AnalyticsEvent(new DistinctId('u'), 'second'));

        $drained = $spool->drain(10);

        $this->assertSame('first', $drained[0]->name);
        $this->assertSame('second', $drained[1]->name);
    }

    public function test_drain_on_empty_spool_returns_empty_list(): void
    {
        $this->assertSame([], $this->spool()->drain(10));
    }

    public function test_stats_reports_record_count(): void
    {
        $spool = $this->spool();
        $spool->enqueue(new AnalyticsEvent(new DistinctId('u'), 'evt'));

        $this->assertSame(1, $spool->stats()->recordCount);
    }

    private function spool(): AnalyticsSpool
    {
        $path = sys_get_temp_dir() . '/vortos-analytics-test-' . bin2hex(random_bytes(8)) . '/events.spool';

        return new AnalyticsSpool(new BoundedSpool($path, 1024 * 1024));
    }
}
