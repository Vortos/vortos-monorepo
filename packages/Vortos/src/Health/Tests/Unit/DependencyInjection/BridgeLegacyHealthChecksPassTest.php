<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\Foundation\Health\Attribute\AsHealthCheck;
use Vortos\Foundation\Health\Contract\HealthCheckInterface;
use Vortos\Foundation\Health\HealthResult;
use Vortos\Health\DependencyInjection\Compiler\BridgeLegacyHealthChecksPass;
use Vortos\Health\DependencyInjection\Compiler\CollectHealthProbesPass;
use Vortos\Foundation\Health\Contract\HealthCheckKind;
use Vortos\Health\Probe\Bridge\LegacyHealthCheckProbe;
use Vortos\Health\Probe\ProbeKind;

final class BridgeLegacyHealthChecksPassTest extends TestCase
{
    public function testBridgesAsHealthCheckServices(): void
    {
        $container = new ContainerBuilder();

        $checkClass = $this->createLegacyCheckClass('StubHealthCheck', true);

        $def = new Definition($checkClass);
        $container->setDefinition('test.stub_check', $def);

        $pass = new BridgeLegacyHealthChecksPass();
        $pass->process($container);

        $bridgeId = 'vortos.health.bridge.stub';
        self::assertTrue($container->hasDefinition($bridgeId));

        $bridgeDef = $container->getDefinition($bridgeId);
        self::assertSame(LegacyHealthCheckProbe::class, $bridgeDef->getClass());

        $tags = $bridgeDef->getTag(CollectHealthProbesPass::TAG);
        self::assertNotEmpty($tags);
        self::assertSame('legacy-stub', $tags[0]['key']);
    }

    public function testSkipsNonHealthCheckServices(): void
    {
        $container = new ContainerBuilder();

        $def = new Definition(\stdClass::class);
        $container->setDefinition('test.other', $def);

        $pass = new BridgeLegacyHealthChecksPass();
        $pass->process($container);

        $bridgeIds = array_filter(
            array_keys($container->getDefinitions()),
            static fn (string $id) => str_starts_with($id, 'vortos.health.bridge.'),
        );

        self::assertEmpty($bridgeIds);
    }

    public function testPreservesCriticalFlag(): void
    {
        $container = new ContainerBuilder();

        $checkClass = $this->createLegacyCheckClass('CriticalHealthCheck', true);
        $container->setDefinition('test.critical', new Definition($checkClass));

        $nonCriticalClass = $this->createLegacyCheckClass('OptionalHealthCheck', false);
        $container->setDefinition('test.non_critical', new Definition($nonCriticalClass));

        $pass = new BridgeLegacyHealthChecksPass();
        $pass->process($container);

        $criticalBridge = $container->getDefinition('vortos.health.bridge.critical');
        $optionalBridge = $container->getDefinition('vortos.health.bridge.optional');

        self::assertTrue($criticalBridge->getArgument('$critical'));
        self::assertFalse($optionalBridge->getArgument('$critical'));
    }

    public function testDefaultsToReadinessAndHonoursAnExplicitMonitoringKind(): void
    {
        $container = new ContainerBuilder();

        $container->setDefinition('test.gating', new Definition(
            $this->createLegacyCheckClass('GatingHealthCheck', true),
        ));
        $container->setDefinition('test.external', new Definition(
            $this->createLegacyCheckClass('ExternalHealthCheck', true, HealthCheckKind::Monitoring),
        ));

        (new BridgeLegacyHealthChecksPass())->process($container);

        // An undeclared kind must keep gating traffic, or upgrading the framework would silently
        // stop a database check from holding an unready instance out of the pool.
        self::assertSame(
            ProbeKind::Readiness,
            $container->getDefinition('vortos.health.bridge.gating')->getArgument('$kind'),
        );
        // A declared Monitoring check must land off-gate — this is the whole point of the seam.
        self::assertSame(
            ProbeKind::Monitoring,
            $container->getDefinition('vortos.health.bridge.external')->getArgument('$kind'),
        );
    }

    private function createLegacyCheckClass(
        string $shortName,
        bool $critical,
        HealthCheckKind $kind = HealthCheckKind::Readiness,
    ): string {
        $ns = 'Vortos\\Health\\Tests\\Fixtures\\Generated';
        $fqcn = $ns . '\\' . $shortName;

        if (class_exists($fqcn)) {
            return $fqcn;
        }

        $criticalStr = $critical ? 'true' : 'false';
        $kindStr = 'HealthCheckKind::' . $kind->name;

        eval(<<<PHP
namespace {$ns};

use Vortos\\Foundation\\Health\\Attribute\\AsHealthCheck;
use Vortos\\Foundation\\Health\\Contract\\HealthCheckInterface;
use Vortos\\Foundation\\Health\\Contract\\HealthCheckKind;
use Vortos\\Foundation\\Health\\HealthResult;

#[AsHealthCheck(critical: {$criticalStr}, kind: {$kindStr})]
final class {$shortName} implements HealthCheckInterface
{
    public function name(): string { return 'test'; }
    public function check(): HealthResult { return new HealthResult('test', true, 0.1); }
}
PHP);

        return $fqcn;
    }
}
