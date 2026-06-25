<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Contract\FlagGateReadiness;
use Vortos\Deploy\Contract\ManualReadiness;
use Vortos\Deploy\Contract\SoakWindowReadiness;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\State\CurrentRelease;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\FeatureFlags\Guardrail\GuardrailMetricKind;
use Vortos\FeatureFlags\Guardrail\MetricSource\InMemoryGuardrailMetricSource;
use Vortos\Migration\Schema\FlagGateMetadataReaderInterface;
use Vortos\Migration\Schema\FlagGateSpec;

final class ContractReadinessTest extends TestCase
{
    public function test_manual_never_clears(): void
    {
        $readiness = new ManualReadiness();

        self::assertFalse($readiness->isCleared('m001', new EnvironmentName('prod')));
        self::assertStringContainsString('Manual', $readiness->reason('m001'));
        self::assertStringContainsString('force-contract', $readiness->reason('m001'));
    }

    public function test_soak_window_default_not_cleared(): void
    {
        $stateStore = new FakeDeployStateStore();
        $readiness = new SoakWindowReadiness($stateStore, $stateStore);

        self::assertFalse($readiness->isCleared('m001', new EnvironmentName('prod')));
        self::assertStringContainsString('Soak window', $readiness->reason('m001'));
    }

    public function test_soak_window_not_cleared_before_deploy_count_or_time_elapses(): void
    {
        $stateStore = new FakeDeployStateStore();
        $stateStore->recordContractSoakObservation('prod', 'm001', 1);
        $stateStore->recordCurrentRelease($this->release('prod', 1));

        $readiness = new SoakWindowReadiness($stateStore, $stateStore, requiredDeployCount: 2, soakDurationSeconds: 3600);

        self::assertFalse($readiness->isCleared('m001', new EnvironmentName('prod')));
    }

    public function test_soak_window_clears_once_required_deploy_count_elapses(): void
    {
        $stateStore = new FakeDeployStateStore();
        $stateStore->recordContractSoakObservation('prod', 'm001', 1);
        $stateStore->recordCurrentRelease($this->release('prod', 3));

        $readiness = new SoakWindowReadiness($stateStore, $stateStore, requiredDeployCount: 2, soakDurationSeconds: 3600);

        self::assertTrue($readiness->isCleared('m001', new EnvironmentName('prod')));
    }

    public function test_soak_window_clears_once_duration_elapses(): void
    {
        $stateStore = new FakeDeployStateStore();
        $stateStore->recordContractSoakObservation('prod', 'm001', 1);
        $stateStore->recordCurrentRelease($this->release('prod', 1));

        $readiness = new SoakWindowReadiness($stateStore, $stateStore, requiredDeployCount: 100, soakDurationSeconds: -1);

        self::assertTrue($readiness->isCleared('m001', new EnvironmentName('prod')));
    }

    public function test_soak_window_isolates_by_environment(): void
    {
        $stateStore = new FakeDeployStateStore();
        $stateStore->recordContractSoakObservation('staging', 'm001', 1);
        $stateStore->recordCurrentRelease($this->release('staging', 10));

        $readiness = new SoakWindowReadiness($stateStore, $stateStore, requiredDeployCount: 2, soakDurationSeconds: 3600);

        self::assertFalse($readiness->isCleared('m001', new EnvironmentName('prod')));
    }

    private function release(string $env, int $generation): CurrentRelease
    {
        return new CurrentRelease(
            env: $env,
            activeColor: ActiveColor::Blue,
            imageDigest: 'sha256:' . str_repeat('a', 64),
            buildId: 'build-' . $generation,
            planHash: 'sha256:plan-' . $generation,
            recordedAt: new \DateTimeImmutable(),
            generation: $generation,
        );
    }

    public function test_flag_gate_default_not_cleared(): void
    {
        $flagGateReader = $this->createMock(FlagGateMetadataReaderInterface::class);
        $flagGateReader->method('flagGateFor')->willReturn(null);

        $readiness = new FlagGateReadiness($flagGateReader);

        self::assertFalse($readiness->isCleared('m001', new EnvironmentName('prod')));
        self::assertStringContainsString('exposure telemetry', $readiness->reason('m001'));
    }

    public function test_flag_gate_not_cleared_without_gated_by_flag_declaration(): void
    {
        $flagGateReader = $this->createMock(FlagGateMetadataReaderInterface::class);
        $flagGateReader->method('flagGateFor')->willReturn(null);

        $metricSource = new InMemoryGuardrailMetricSource();
        $metricSource->set(GuardrailMetricKind::ExposureRateDrop, 'drop-email-old', 'prod', 0.0, 'legacy');

        $readiness = new FlagGateReadiness($flagGateReader, $metricSource);

        self::assertFalse($readiness->isCleared('m001', new EnvironmentName('prod')));
    }

    public function test_flag_gate_not_cleared_without_metric_source(): void
    {
        $flagGateReader = $this->createMock(FlagGateMetadataReaderInterface::class);
        $flagGateReader->method('flagGateFor')->willReturn(new FlagGateSpec('drop-email-old', 'legacy'));

        $readiness = new FlagGateReadiness($flagGateReader, null);

        self::assertFalse($readiness->isCleared('m001', new EnvironmentName('prod')));
    }

    public function test_flag_gate_not_cleared_when_metric_unknown(): void
    {
        $flagGateReader = $this->createMock(FlagGateMetadataReaderInterface::class);
        $flagGateReader->method('flagGateFor')->willReturn(new FlagGateSpec('drop-email-old', 'legacy'));

        $metricSource = new InMemoryGuardrailMetricSource();
        // No value set for this flag/variant — query() returns null (unknown).

        $readiness = new FlagGateReadiness($flagGateReader, $metricSource);

        self::assertFalse($readiness->isCleared('m001', new EnvironmentName('prod')));
    }

    public function test_flag_gate_not_cleared_while_old_variant_still_exposed(): void
    {
        $flagGateReader = $this->createMock(FlagGateMetadataReaderInterface::class);
        $flagGateReader->method('flagGateFor')->willReturn(new FlagGateSpec('drop-email-old', 'legacy'));

        $metricSource = new InMemoryGuardrailMetricSource();
        $metricSource->set(GuardrailMetricKind::ExposureRateDrop, 'drop-email-old', 'prod', 0.42, 'legacy');

        $readiness = new FlagGateReadiness($flagGateReader, $metricSource);

        self::assertFalse($readiness->isCleared('m001', new EnvironmentName('prod')));
    }

    public function test_flag_gate_clears_when_old_variant_exposure_is_zero(): void
    {
        $flagGateReader = $this->createMock(FlagGateMetadataReaderInterface::class);
        $flagGateReader->method('flagGateFor')->willReturn(new FlagGateSpec('drop-email-old', 'legacy'));

        $metricSource = new InMemoryGuardrailMetricSource();
        $metricSource->set(GuardrailMetricKind::ExposureRateDrop, 'drop-email-old', 'prod', 0.0, 'legacy');

        $readiness = new FlagGateReadiness($flagGateReader, $metricSource);

        self::assertTrue($readiness->isCleared('m001', new EnvironmentName('prod')));
    }

    public function test_manual_capabilities(): void
    {
        $readiness = new ManualReadiness();
        $caps = $readiness->capabilities();

        self::assertFalse($caps->supports('deploy-count-soak'));
        self::assertFalse($caps->supports('time-window-soak'));
    }

    public function test_soak_window_capabilities(): void
    {
        $stateStore = new FakeDeployStateStore();
        $readiness = new SoakWindowReadiness($stateStore, $stateStore);
        $caps = $readiness->capabilities();

        self::assertTrue($caps->supports('deploy-count-soak'));
        self::assertTrue($caps->supports('time-window-soak'));
    }

    public function test_flag_gate_capabilities(): void
    {
        $flagGateReader = $this->createMock(FlagGateMetadataReaderInterface::class);
        $readiness = new FlagGateReadiness($flagGateReader);
        $caps = $readiness->capabilities();

        self::assertTrue($caps->supports('exposure-telemetry'));
    }
}
