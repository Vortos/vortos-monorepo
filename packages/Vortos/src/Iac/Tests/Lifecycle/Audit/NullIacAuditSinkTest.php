<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle\Audit;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\Audit\LifecycleEvent;
use Vortos\Iac\Lifecycle\Audit\NullIacAuditSink;
use Vortos\Iac\Lifecycle\LifecyclePhase;

final class NullIacAuditSinkTest extends TestCase
{
    public function test_record_is_noop(): void
    {
        $sink = new NullIacAuditSink();
        $event = new LifecycleEvent(LifecyclePhase::Plan, 'dev', 'digest', 'user', 'summary', '1.0', '2026-01-01T00:00:00+00:00');
        $sink->record($event);
        $this->assertTrue(true);
    }
}
