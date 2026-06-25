<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Probe;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\HealthProbeRegistry;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Tests\Fixtures\StubProbe;
use Vortos\OpsKit\Driver\Exception\UnknownDriverException;

final class HealthProbeRegistryTest extends TestCase
{
    public function testProbeReturnsRegisteredProbe(): void
    {
        $probe = StubProbe::readiness('db');
        $registry = $this->registry(['db' => $probe]);

        self::assertSame($probe, $registry->probe('db'));
    }

    public function testProbeThrowsOnUnknownKey(): void
    {
        $registry = $this->registry(['db' => StubProbe::readiness('db')]);

        $this->expectException(UnknownDriverException::class);
        $registry->probe('nonexistent');
    }

    public function testHasReturnsTrueForRegistered(): void
    {
        $registry = $this->registry(['db' => StubProbe::readiness('db')]);

        self::assertTrue($registry->has('db'));
        self::assertFalse($registry->has('nonexistent'));
    }

    public function testKeysReturnsSortedKeys(): void
    {
        $registry = $this->registry([
            'redis' => StubProbe::readiness('redis'),
            'db' => StubProbe::readiness('db'),
            'kafka' => StubProbe::readiness('kafka'),
        ]);

        self::assertSame(['db', 'kafka', 'redis'], $registry->keys());
    }

    public function testAllProbesReturnsAll(): void
    {
        $registry = $this->registry([
            'db' => StubProbe::readiness('db'),
            'redis' => StubProbe::readiness('redis'),
        ]);

        $all = $registry->allProbes();

        self::assertCount(2, $all);
        self::assertContainsOnlyInstancesOf(HealthProbeInterface::class, $all);
    }

    public function testProbesOfKindFiltersCorrectly(): void
    {
        $registry = $this->registry([
            'db' => StubProbe::readiness('db'),
            'live' => StubProbe::liveness('live'),
            'redis' => StubProbe::readiness('redis'),
        ]);

        $readiness = $registry->probesOfKind(ProbeKind::Readiness);
        $liveness = $registry->probesOfKind(ProbeKind::Liveness);
        $startup = $registry->probesOfKind(ProbeKind::Startup);

        self::assertCount(2, $readiness);
        self::assertCount(1, $liveness);
        self::assertCount(0, $startup);
    }

    public function testEmptyRegistry(): void
    {
        $registry = $this->registry([]);

        self::assertSame([], $registry->keys());
        self::assertSame([], $registry->allProbes());
        self::assertSame([], $registry->probesOfKind(ProbeKind::Readiness));
    }

    /** @param array<string, HealthProbeInterface> $probes */
    private function registry(array $probes): HealthProbeRegistry
    {
        $locator = new ServiceLocator(
            array_map(
                static fn ($p) => static fn () => $p,
                $probes,
            ),
        );

        return new HealthProbeRegistry($locator);
    }
}
