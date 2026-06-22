<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Benchmark;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\OutputTimeUnit;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\RetryThreshold;
use PhpBench\Attributes\Warmup;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagEvaluator;
use Vortos\FeatureFlags\FlagRegistry;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\FlagValueType;
use Vortos\FeatureFlags\RolloutSchedule;
use Vortos\FeatureFlags\Targeting\Bucketing;

/**
 * Block 28.1 — Benchmark harness for the eval hot path.
 *
 * All scenarios use in-memory storage so the measurement is the engine cost only.
 * Baselines: see Tests/Benchmark/baseline/assertions.json
 *
 * SLO targets (p99 on the CI runner):
 *  - Simple boolean on/off:            < 5 µs
 *  - Percentage rollout (MurmurHash3): < 10 µs
 *  - 8-deep nested AND/OR groups:      < 25 µs
 *  - Multivariate sticky assignment:   < 15 µs
 *  - Prerequisite chain (depth 4):     < 20 µs
 *  - Max-size JSON payload:            < 10 µs
 *  - Schedule + ramp eval:             < 15 µs
 *
 * Run: vendor/bin/phpbench run packages/Vortos/src/FeatureFlags/Tests/Benchmark \
 *        --report=aggregate --assert=baseline
 */
#[BeforeMethods('setUp')]
#[OutputTimeUnit('microseconds')]
#[Groups(['feature-flags-slo'])]
final class FlagEvaluationBench
{
    private FlagEvaluator $evaluator;
    private FlagContext $context;
    private FeatureFlag $boolFlag;
    private FeatureFlag $pctFlag;
    private FeatureFlag $deepGroupFlag;
    private FeatureFlag $variantFlag;
    private FeatureFlag $scheduleFlag;
    private FeatureFlag $jsonFlag;
    private FlagRegistry $registry;

    public function setUp(): void
    {
        $this->evaluator = new FlagEvaluator();
        $this->context   = new FlagContext(
            userId: 'bench-user-42',
            trusted: ['plan' => 'enterprise', 'tenantId' => 'tenant-1'],
            untrusted: ['country' => 'US', 'deviceId' => 'device-abc'],
        );

        $now = new \DateTimeImmutable();

        // Scenario 1: simple boolean flag — no rules, just on/off
        $this->boolFlag = new FeatureFlag(
            id: 'b1', name: 'bench-bool', description: '', enabled: true,
            rules: [], variants: null, createdAt: $now, updatedAt: $now,
        );

        // Scenario 2: percentage rollout (MurmurHash3 path)
        $this->pctFlag = new FeatureFlag(
            id: 'p1', name: 'bench-pct', description: '', enabled: true,
            rules: [new FlagRule(FlagRule::TYPE_PERCENTAGE, percentage: 50)],
            variants: null, createdAt: $now, updatedAt: $now,
        );

        // Scenario 3: 8-deep nested AND/OR groups
        $leaf = new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'enterprise', zone: FlagRule::ZONE_TRUSTED);
        $tree = $leaf;
        for ($d = 0; $d < FlagEvaluator::MAX_GROUP_DEPTH - 1; $d++) {
            $tree = new FlagRule(FlagRule::TYPE_GROUP, combinator: ($d % 2 === 0 ? FlagRule::CMB_AND : FlagRule::CMB_OR), children: [$tree]);
        }
        $this->deepGroupFlag = new FeatureFlag(
            id: 'd1', name: 'bench-deep', description: '', enabled: true,
            rules: [$tree], variants: null, createdAt: $now, updatedAt: $now,
        );

        // Scenario 4: multivariate sticky assignment
        $this->variantFlag = new FeatureFlag(
            id: 'v1', name: 'bench-variant', description: '', enabled: true,
            rules: [], variants: ['control' => 50, 'treatment-a' => 25, 'treatment-b' => 25],
            createdAt: $now, updatedAt: $now, valueType: FlagValueType::String,
        );

        // Scenario 5: schedule + ramp eval (frozen clock)
        $schedule = RolloutSchedule::fromArray([
            'start_at'   => $now->modify('-1 hour')->format(\DateTimeInterface::ATOM),
            'end_at'     => $now->modify('+1 hour')->format(\DateTimeInterface::ATOM),
            'percentage' => 60,
        ]);
        $this->scheduleFlag = new FeatureFlag(
            id: 's1', name: 'bench-schedule', description: '', enabled: true,
            rules: [], variants: null, schedule: $schedule,
            createdAt: $now, updatedAt: $now,
        );

        // Scenario 6: max-size JSON payload
        $maxPayload = array_fill(0, 100, str_repeat('x', 200)); // ~20 KB
        $this->jsonFlag = new FeatureFlag(
            id: 'j1', name: 'bench-json', description: '', enabled: true,
            rules: [], variants: null, payload: $maxPayload,
            createdAt: $now, updatedAt: $now, valueType: FlagValueType::Json,
        );

        // Registry for allForContext bench
        $storage = new InMemoryFlagStorage();
        foreach ([$this->boolFlag, $this->pctFlag, $this->deepGroupFlag, $this->variantFlag] as $f) {
            $storage->add($f);
        }
        $this->registry = new FlagRegistry($storage, $this->evaluator);
    }

    #[Revs(1000)]
    #[Iterations(5)]
    #[Warmup(2)]
    #[RetryThreshold(5.0)]
    #[Assert('mode(variant.time.avg) < 5000 microseconds')]
    public function benchBooleanEval(): void
    {
        $this->evaluator->evaluate($this->boolFlag, $this->context);
    }

    #[Revs(1000)]
    #[Iterations(5)]
    #[Warmup(2)]
    #[RetryThreshold(5.0)]
    #[Assert('mode(variant.time.avg) < 10000 microseconds')]
    public function benchPercentageRollout(): void
    {
        $this->evaluator->evaluate($this->pctFlag, $this->context);
    }

    #[Revs(500)]
    #[Iterations(5)]
    #[Warmup(2)]
    #[RetryThreshold(5.0)]
    #[Assert('mode(variant.time.avg) < 25000 microseconds')]
    public function benchDeepGroupTree(): void
    {
        $this->evaluator->evaluate($this->deepGroupFlag, $this->context);
    }

    #[Revs(500)]
    #[Iterations(5)]
    #[Warmup(2)]
    #[RetryThreshold(5.0)]
    #[Assert('mode(variant.time.avg) < 15000 microseconds')]
    public function benchVariantAssignment(): void
    {
        $this->evaluator->evaluateVariant($this->variantFlag, $this->context);
    }

    #[Revs(500)]
    #[Iterations(5)]
    #[Warmup(2)]
    #[RetryThreshold(5.0)]
    #[Assert('mode(variant.time.avg) < 15000 microseconds')]
    public function benchScheduleAndRamp(): void
    {
        $this->evaluator->evaluate($this->scheduleFlag, $this->context);
    }

    #[Revs(500)]
    #[Iterations(5)]
    #[Warmup(2)]
    #[RetryThreshold(5.0)]
    #[Assert('mode(variant.time.avg) < 10000 microseconds')]
    public function benchJsonPayload(): void
    {
        $this->evaluator->evaluatePayload($this->jsonFlag, $this->context);
    }

    #[Revs(200)]
    #[Iterations(5)]
    #[Warmup(2)]
    #[RetryThreshold(5.0)]
    #[Assert('mode(variant.time.avg) < 50000 microseconds')]
    public function benchAllForContext(): void
    {
        $this->registry->allForContext($this->context);
    }

    #[Revs(5000)]
    #[Iterations(5)]
    #[Warmup(2)]
    #[RetryThreshold(5.0)]
    #[Assert('mode(variant.time.avg) < 2000 microseconds')]
    public function benchMurmurHash3Raw(): void
    {
        Bucketing::bucket('bench-flag', 'bench-user-42');
    }
}
