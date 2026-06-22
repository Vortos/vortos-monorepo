<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Guardrail;

use PHPUnit\Framework\TestCase;
use Vortos\Domain\Event\DomainEventLedger;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Guardrail\GuardrailAction;
use Vortos\FeatureFlags\Guardrail\GuardrailCondition;
use Vortos\FeatureFlags\Guardrail\GuardrailConditionEvaluator;
use Vortos\FeatureFlags\Guardrail\GuardrailMetricKind;
use Vortos\FeatureFlags\Guardrail\GuardrailPolicy;
use Vortos\FeatureFlags\Guardrail\GuardrailStatus;
use Vortos\FeatureFlags\Guardrail\GuardrailWatcherService;
use Vortos\FeatureFlags\Guardrail\MetricSource\InMemoryGuardrailMetricSource;
use Vortos\FeatureFlags\Guardrail\Storage\GuardrailPolicyStorageInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

final class GuardrailCooldownTest extends TestCase
{
    private InMemoryGuardrailMetricSource $metrics;
    private GuardrailWatcherService $watcher;
    private GuardrailPolicyStorageInterface $storage;
    private MutableClock $clock;
    private int $disableCalls = 0;

    protected function setUp(): void
    {
        DomainEventLedger::discard();
        $this->disableCalls = 0;
        $this->clock        = new MutableClock(new \DateTimeImmutable('2026-06-22T12:00:00+00:00'));
        $this->metrics      = new InMemoryGuardrailMetricSource();

        $flag = new FeatureFlag(
            id: '11111111-1111-4111-8111-111111111111', name: 'checkout', description: '', enabled: true, rules: [], variants: null,
            createdAt: new \DateTimeImmutable(), updatedAt: new \DateTimeImmutable(),
        );
        $flagStorage = $this->createMock(FlagStorageInterface::class);
        $flagStorage->method('findByName')->willReturnCallback(fn() => $flag);
        $flagStorage->method('save')->willReturnCallback(function (FeatureFlag $f): void {
            $this->disableCalls++;
        });

        $uow = $this->createMock(UnitOfWorkInterface::class);
        $uow->method('run')->willReturnCallback(static fn(callable $w) => $w());
        $eventBus = $this->createMock(EventBusInterface::class);

        $this->storage = $this->inMemoryStorage();

        $this->watcher = new GuardrailWatcherService(
            storage: $this->storage,
            conditionEvaluator: new GuardrailConditionEvaluator($this->metrics),
            metricSource: $this->metrics,
            writeService: new FlagWriteService(storage: $flagStorage, unitOfWork: $uow, eventBus: $eventBus),
            flagStorage: $flagStorage,
            scopeContext: new FlagScopeContext(),
            eventBus: $eventBus,
            clock: $this->clock,
        );
    }

    protected function tearDown(): void
    {
        DomainEventLedger::discard();
    }

    public function test_no_re_evaluation_within_cooldown(): void
    {
        $this->storage->save($this->policy());
        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.20);

        $this->watcher->evaluate();           // trigger (windows=1) → 1 disable call
        $this->assertSame(1, $this->disableCalls);

        $this->clock->advance(120);           // still within 600s cooldown
        $this->watcher->evaluate();           // must be skipped → still triggered, no new action

        $this->assertSame(1, $this->disableCalls);
        $this->assertSame(GuardrailStatus::Triggered->value, $this->storage->findById('gp')->status);
    }

    public function test_re_evaluates_after_cooldown(): void
    {
        $this->storage->save($this->policy());
        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.20);

        $this->watcher->evaluate();           // trigger
        $this->clock->advance(601);           // cooldown elapsed, metric still breaching
        $this->watcher->evaluate();           // re-arms and fires again

        $this->assertSame(2, $this->disableCalls);
    }

    private function policy(): GuardrailPolicy
    {
        return new GuardrailPolicy(
            id: 'gp', flagName: 'checkout', projectId: 'default', environment: 'production',
            status: GuardrailStatus::Watching->value, action: GuardrailAction::Disable, pauseRampTargetPct: null,
            consecutiveWindows: 1, windowSeconds: 300, cooldownSeconds: 600, enabled: true,
            consecutiveBreachCount: 0, lastEvaluatedAt: null, triggeredAt: null, resolvedAt: null,
            createdAt: new \DateTimeImmutable(), createdBy: 'admin',
            conditions: [new GuardrailCondition('c', null, GuardrailMetricKind::ErrorRate, null, 0.05, 'gt', 0)],
        );
    }

    private function inMemoryStorage(): GuardrailPolicyStorageInterface
    {
        return new class implements GuardrailPolicyStorageInterface {
            /** @var array<string, GuardrailPolicy> */
            private array $rows = [];
            public function save(GuardrailPolicy $policy): void { $this->rows[$policy->id] = $policy; }
            public function findById(string $id): ?GuardrailPolicy { return $this->rows[$id] ?? null; }
            public function findEnabled(string $projectId, string $environment): array { return array_values($this->rows); }
            public function findDueForEvaluation(\DateTimeImmutable $before, int $limit): array { return array_values($this->rows); }
            public function delete(string $id): void { unset($this->rows[$id]); }
        };
    }
}
