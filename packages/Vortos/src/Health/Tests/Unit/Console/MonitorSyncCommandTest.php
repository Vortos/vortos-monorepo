<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Health\Console\MonitorSyncCommand;
use Vortos\Health\Monitor\InMemorySyncRecordStore;
use Vortos\Health\Monitor\MonitorDescriptorHasher;
use Vortos\Health\Tests\Fixtures\FakeUptimeMonitor;
use Vortos\Health\Uptime\JourneyStep;
use Vortos\Health\Uptime\MonitorDescriptor;
use Vortos\Health\Uptime\MonitorDescriptorSet;
use Vortos\Health\Uptime\SyntheticJourney;
use Vortos\Health\Uptime\UptimeMonitorRegistry;

final class MonitorSyncCommandTest extends TestCase
{
    private function descriptor(): MonitorDescriptor
    {
        return new MonitorDescriptor('login-fetch', 'Login then fetch', new SyntheticJourney('login-fetch', [
            new JourneyStep('POST', '/login', 200, extractAs: 'token', extractJsonPath: 'data.token'),
            new JourneyStep('GET', '/me', 200, bodyContains: '"email"'),
        ]));
    }

    private function registryFor(FakeUptimeMonitor $monitor): UptimeMonitorRegistry
    {
        $container = new class($monitor) implements ContainerInterface {
            public function __construct(private FakeUptimeMonitor $monitor) {}

            public function get(string $id): mixed
            {
                return $this->monitor;
            }

            public function has(string $id): bool
            {
                return $id === 'fake';
            }
        };

        return new UptimeMonitorRegistry($container);
    }

    public function testDryRunIsTheDefaultAndMutatesNothing(): void
    {
        $monitor = new FakeUptimeMonitor();
        $store = new InMemorySyncRecordStore();

        $command = new MonitorSyncCommand(
            new MonitorDescriptorSet([$this->descriptor()]),
            $this->registryFor($monitor),
            $store,
            new MonitorDescriptorHasher(),
        );

        $tester = new CommandTester($command);
        $tester->execute(['journey-key' => 'login-fetch', 'env' => 'prod', '--driver' => 'fake']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('dry-run', $tester->getDisplay());
        self::assertSame(0, $monitor->syncCallCount());
        self::assertNull($store->lastHash('prod', 'login-fetch'));
    }

    public function testApplyWritesAndRecordsTheHash(): void
    {
        $monitor = new FakeUptimeMonitor();
        $store = new InMemorySyncRecordStore();

        $command = new MonitorSyncCommand(
            new MonitorDescriptorSet([$this->descriptor()]),
            $this->registryFor($monitor),
            $store,
            new MonitorDescriptorHasher(),
        );

        $tester = new CommandTester($command);
        $tester->execute(['journey-key' => 'login-fetch', 'env' => 'prod', '--driver' => 'fake', '--apply' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(1, $monitor->syncCallCount());
        self::assertNotNull($store->lastHash('prod', 'login-fetch'));
    }

    public function testSecondApplyWithUnchangedDescriptorIsANoOp(): void
    {
        $monitor = new FakeUptimeMonitor();
        $store = new InMemorySyncRecordStore();
        $descriptors = new MonitorDescriptorSet([$this->descriptor()]);
        $hasher = new MonitorDescriptorHasher();

        $command = new MonitorSyncCommand($descriptors, $this->registryFor($monitor), $store, $hasher);
        (new CommandTester($command))->execute(['journey-key' => 'login-fetch', 'env' => 'prod', '--driver' => 'fake', '--apply' => true]);

        self::assertSame(1, $monitor->syncCallCount());

        $command2 = new MonitorSyncCommand($descriptors, $this->registryFor($monitor), $store, $hasher);
        $tester2 = new CommandTester($command2);
        $tester2->execute(['journey-key' => 'login-fetch', 'env' => 'prod', '--driver' => 'fake', '--apply' => true]);

        self::assertSame(0, $tester2->getStatusCode());
        self::assertStringContainsString('no-op', $tester2->getDisplay());
        self::assertSame(1, $monitor->syncCallCount(), 'unchanged descriptor must not trigger a second provider write');
    }

    public function testUnknownJourneyKeyFails(): void
    {
        $command = new MonitorSyncCommand(
            new MonitorDescriptorSet([]),
            $this->registryFor(new FakeUptimeMonitor()),
            new InMemorySyncRecordStore(),
            new MonitorDescriptorHasher(),
        );

        $tester = new CommandTester($command);
        $tester->execute(['journey-key' => 'does-not-exist', 'env' => 'prod']);

        self::assertSame(1, $tester->getStatusCode());
    }

    public function testJsonOutputIsValidJson(): void
    {
        $command = new MonitorSyncCommand(
            new MonitorDescriptorSet([$this->descriptor()]),
            $this->registryFor(new FakeUptimeMonitor()),
            new InMemorySyncRecordStore(),
            new MonitorDescriptorHasher(),
        );

        $tester = new CommandTester($command);
        $tester->execute(['journey-key' => 'login-fetch', 'env' => 'prod', '--driver' => 'fake', '--json' => true]);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertSame('login-fetch', $decoded['journey_key']);
        self::assertSame('dry-run', $decoded['outcome']);
    }
}
