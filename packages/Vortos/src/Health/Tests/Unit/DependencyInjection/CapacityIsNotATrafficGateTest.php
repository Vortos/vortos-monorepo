<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\DependencyInjection\HealthExtension;
use Vortos\Health\Probe\Capacity\CpuLoadProbe;
use Vortos\Health\Probe\Capacity\DiskCapacityProbe;
use Vortos\Health\Probe\Capacity\MemoryCapacityProbe;

/**
 * Resource pressure must not, by default, remove an instance from service.
 *
 * Readiness answers one question: can this instance serve requests. A loaded CPU, a filling disk
 * and a tight heap all mean it serves SLOWLY, not that it cannot serve. Failing readiness on them
 * is only ever useful when there is another replica to send the traffic to — and when there is not,
 * it turns a performance problem into a complete outage, at the exact moment the system is least
 * able to absorb one.
 *
 * That is not theoretical. This defaulted to gating readiness. Crash-looping consumers drove load
 * average to 34 on a 4-core box, the CPU probe failed readiness, the edge pulled the only serving
 * colour out of rotation, and the public API served 503 — while the application was answering its
 * own health endpoint correctly the entire time. The probe reporting the problem caused the outage.
 *
 * So capacity is Monitoring by default: measured, exported, alertable, never a traffic gate.
 * Deployments with spare replicas that genuinely want pressure-based shedding opt in explicitly.
 */
final class CapacityIsNotATrafficGateTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['HEALTH_CAPACITY_READINESS'] as $key) {
            $this->saved[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    private function build(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new HealthExtension())->load([], $container);

        return $container;
    }

    /** @return list<class-string> */
    public static function capacityProbes(): array
    {
        return [DiskCapacityProbe::class, MemoryCapacityProbe::class, CpuLoadProbe::class];
    }

    public function test_capacity_probes_do_not_gate_readiness_by_default(): void
    {
        $container = $this->build();

        foreach (self::capacityProbes() as $probe) {
            self::assertTrue($container->hasDefinition($probe), $probe . ' should still be registered');

            self::assertSame(
                ProbeKind::Monitoring,
                $container->getDefinition($probe)->getArgument('$kind'),
                $probe . ' must not gate readiness by default — pressure is not unavailability, and'
                . ' shedding the only replica to nowhere is worse than serving slowly.',
            );
        }
    }

    public function test_gating_remains_available_for_topologies_that_can_actually_shed(): void
    {
        $_ENV['HEALTH_CAPACITY_READINESS'] = 'true';

        $container = $this->build();

        foreach (self::capacityProbes() as $probe) {
            self::assertSame(
                ProbeKind::Readiness,
                $container->getDefinition($probe)->getArgument('$kind'),
                $probe . ' must still be able to gate readiness when explicitly opted in.',
            );
        }
    }

    /**
     * Turning capacity off as a gate must not turn it off as a signal — otherwise the fix trades an
     * outage for blindness, and the next capacity problem is found by a customer.
     */
    public function test_capacity_is_still_measured_when_it_is_not_a_gate(): void
    {
        $container = $this->build();

        foreach (self::capacityProbes() as $probe) {
            self::assertNotSame(
                [],
                $container->getDefinition($probe)->getTag('vortos.health.probe'),
                $probe . ' must remain a registered probe so it is still collected and alertable.',
            );
        }
    }
}
