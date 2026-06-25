<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Capability\AnalyticsCapability;
use Vortos\Analytics\Command\AnalyticsFlushCommand;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\DistinctId;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\Analytics\Runtime\AnalyticsSpool;
use Vortos\Observability\Buffer\BoundedSpool;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class AnalyticsFlushCommandTest extends TestCase
{
    public function test_drains_spooled_events_through_analytics(): void
    {
        $spool = $this->spool();
        $spool->enqueue(new AnalyticsEvent(new DistinctId('user-1'), 'evt'));
        $spool->enqueue(new AnalyticsEvent(new DistinctId('user-2'), 'evt'));

        $analytics = $this->spyAnalytics();
        $command = new AnalyticsFlushCommand($spool, $analytics);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertCount(2, $analytics->captured);
        $this->assertSame(1, $analytics->flushCount);
        $this->assertStringContainsString('Drained 2', $tester->getDisplay());
    }

    public function test_empty_spool_drains_nothing(): void
    {
        $analytics = $this->spyAnalytics();
        $command = new AnalyticsFlushCommand($this->spool(), $analytics);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([], $analytics->captured);
    }

    public function test_json_output(): void
    {
        $command = new AnalyticsFlushCommand($this->spool(), $this->spyAnalytics());
        $tester = new CommandTester($command);
        $tester->execute(['--json' => true]);

        $payload = json_decode($tester->getDisplay(), true);
        $this->assertSame(0, $payload['drained']);
    }

    private function spool(): AnalyticsSpool
    {
        $path = sys_get_temp_dir() . '/vortos-analytics-test-' . bin2hex(random_bytes(8)) . '/events.spool';

        return new AnalyticsSpool(new BoundedSpool($path, 1024 * 1024));
    }

    private function spyAnalytics(): object
    {
        return new class implements AnalyticsInterface {
            /** @var list<AnalyticsEvent> */
            public array $captured = [];
            public int $flushCount = 0;

            public function name(): string { return 'spy'; }
            public function capture(AnalyticsEvent $event): void { $this->captured[] = $event; }
            public function identify(IdentitySet $identity): void {}
            public function group(GroupAssociation $group): void {}
            public function flush(): void { $this->flushCount++; }
            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([AnalyticsCapability::Batching->value => false]);
            }
        };
    }
}
