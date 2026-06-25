<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle\Audit;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\Audit\LifecycleEvent;
use Vortos\Iac\Lifecycle\LifecyclePhase;

final class LifecycleEventTest extends TestCase
{
    public function test_to_array(): void
    {
        $event = new LifecycleEvent(
            LifecyclePhase::Apply,
            'staging',
            'abc123',
            'deploy-bot',
            'applied 3 resources',
            '1.8.0',
            '2026-06-24T10:00:00+00:00',
        );

        $arr = $event->toArray();
        $this->assertSame('apply', $arr['phase']);
        $this->assertSame('staging', $arr['environment']);
        $this->assertSame('abc123', $arr['plan_digest']);
        $this->assertSame('deploy-bot', $arr['actor']);
        $this->assertSame('applied 3 resources', $arr['summary']);
        $this->assertSame('1.8.0', $arr['binary_version']);
    }
}
