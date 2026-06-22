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
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

final class GuardrailDebounceTest extends TestCase
{
    private InMemoryGuardrailMetricSource $metrics;
    private GuardrailWatcherService $watcher;
    private object $storage;
    private ?FeatureFlag $savedFlag = null;

    protected function setUp(): void
    {
        DomainEventLedger::discard();
        $this->savedFlag = null;
        $clock           = new MutableClock(new \DateTimeImmutable('2026-06-22T12:00:00+00:00'));
        $this->metrics   = new InMemoryGuardrailMetricSource();
        $flag            = new FeatureFlag(
            id: '11111111-1111-4111-8111-111111111111', name: 'checkout', description: '', enabled: true, rules: [], variants: null,
            createdAt: new \DateTimeImmutable(), updatedAt: new \DateTimeImmutable(),
        );

        $flagStorage = $this->createMock(FlagStorageInterface::class);
        $flagStorage->method('findByName')->willReturnCallback(fn() => $flag);
        $flagStorage->method('save')->willReturnCallback(function (FeatureFlag $f): void {
            $this->savedFlag = $f;
        });

        $uow = $this->createMock(UnitOfWorkInterface::class);
        $uow->method('run')->willReturnCallback(static fn(callable $w) => $w());
        $eventBus = $this->createMock(EventBusInterface::class);

        $this->storage = new class {
            /** @var array<string, GuardrailPolicy> */
            public array $rows = [];
            public function save(GuardrailPolicy $p): void { $this->rows[$p->id] = $p; }
            public function get(string $id): GuardrailPolicy { return $this->rows[$id]; }
        };

        $storageAdapter = $this->adapter($this->storage);

        $this->watcher = new GuardrailWatcherService(
            storage: $storageAdapter,
            conditionEvaluator: new GuardrailConditionEvaluator($this->metrics),
            metricSource: $this->metrics,
            writeService: new FlagWriteService(storage: $flagStorage, unitOfWork: $uow, eventBus: $eventBus),
            flagStorage: $flagStorage,
            scopeContext: new FlagScopeContext(),
            eventBus: $eventBus,
            clock: $clock,
        );
    }

    protected function tearDown(): void
    {
        DomainEventLedger::discard();
    }

    public function test_n_minus_one_breaches_do_not_trigger(): void
    {
        $this->storage->save($this->policy(windows: 3));
        $this->breaching();

        $this->watcher->evaluate();
        $this->watcher->evaluate();

        $this->assertSame(2, $this->storage->get('gp')->consecutiveBreachCount);
        $this->assertSame(GuardrailStatus::Watching->value, $this->storage->get('gp')->status);
        $this->assertNull($this->savedFlag);
    }

    public function test_clear_before_quorum_resets(): void
    {
        $this->storage->save($this->policy(windows: 3));
        $this->breaching();
        $this->watcher->evaluate();
        $this->watcher->evaluate();

        $this->clearing();
        $this->watcher->evaluate();

        $this->assertSame(0, $this->storage->get('gp')->consecutiveBreachCount);
    }

    public function test_fresh_streak_after_reset_triggers(): void
    {
        $this->storage->save($this->policy(windows: 3));
        $this->breaching();
        $this->watcher->evaluate();
        $this->watcher->evaluate();
        $this->clearing();
        $this->watcher->evaluate();

        $this->breaching();
        $this->watcher->evaluate();
        $this->watcher->evaluate();
        $this->watcher->evaluate();

        $this->assertSame(GuardrailStatus::Triggered->value, $this->storage->get('gp')->status);
        $this->assertNotNull($this->savedFlag);
        $this->assertFalse($this->savedFlag->enabled);
    }

    private function breaching(): void
    {
        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.20);
    }

    private function clearing(): void
    {
        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.01);
    }

    private function policy(int $windows): GuardrailPolicy
    {
        return new GuardrailPolicy(
            id: 'gp', flagName: 'checkout', projectId: 'default', environment: 'production',
            status: GuardrailStatus::Watching->value, action: GuardrailAction::Disable, pauseRampTargetPct: null,
            consecutiveWindows: $windows, windowSeconds: 300, cooldownSeconds: 600, enabled: true,
            consecutiveBreachCount: 0, lastEvaluatedAt: null, triggeredAt: null, resolvedAt: null,
            createdAt: new \DateTimeImmutable(), createdBy: 'admin',
            conditions: [new GuardrailCondition('c', null, GuardrailMetricKind::ErrorRate, null, 0.05, 'gt', 0)],
        );
    }

    private function adapter(object $store): \Vortos\FeatureFlags\Guardrail\Storage\GuardrailPolicyStorageInterface
    {
        return new class($store) implements \Vortos\FeatureFlags\Guardrail\Storage\GuardrailPolicyStorageInterface {
            public function __construct(private object $store) {}
            public function save(GuardrailPolicy $policy): void { $this->store->save($policy); }
            public function findById(string $id): ?GuardrailPolicy { return $this->store->rows[$id] ?? null; }
            public function findEnabled(string $projectId, string $environment): array { return array_values($this->store->rows); }
            public function findDueForEvaluation(\DateTimeImmutable $before, int $limit): array { return array_values($this->store->rows); }
            public function delete(string $id): void { unset($this->store->rows[$id]); }
        };
    }
}
