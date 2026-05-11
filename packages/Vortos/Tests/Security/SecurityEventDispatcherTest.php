<?php

declare(strict_types=1);

namespace Vortos\Tests\Security;

use PHPUnit\Framework\TestCase;
use Vortos\Metrics\Adapter\NoOpMetrics;
use Vortos\Metrics\AutoInstrumentation\SecurityMetricDefinitions;
use Vortos\Metrics\Definition\MetricDefinitionRegistry;
use Vortos\Security\Contract\SecurityEventInterface;
use Vortos\Security\Event\SecurityEventDispatcher;

final class SecurityEventDispatcherTest extends TestCase
{
    public function test_noop_metrics_security_counter_does_not_throw(): void
    {
        $definitions = new SecurityMetricDefinitions();
        $dispatcher = new SecurityEventDispatcher(null, new NoOpMetrics(new MetricDefinitionRegistry($definitions->definitions())));

        $dispatcher->dispatch(new class implements SecurityEventInterface {
            public function eventName(): string
            {
                return 'security.csrf_violation';
            }

            public function context(): array
            {
                return [];
            }
        });

        $this->assertTrue(true);
    }
}
