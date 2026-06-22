<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Guardrail;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Vortos\Domain\Event\DomainEventLedger;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Guardrail\Domain\Event\GuardrailResolvedEvent;
use Vortos\FeatureFlags\Guardrail\Domain\Event\GuardrailTriggeredEvent;
use Vortos\FeatureFlags\Guardrail\GuardrailAction;
use Vortos\FeatureFlags\Guardrail\GuardrailCondition;
use Vortos\FeatureFlags\Guardrail\GuardrailConditionEvaluator;
use Vortos\FeatureFlags\Guardrail\GuardrailMetricKind;
use Vortos\FeatureFlags\Guardrail\GuardrailPolicy;
use Vortos\FeatureFlags\Guardrail\GuardrailStatus;
use Vortos\FeatureFlags\Guardrail\GuardrailWatcherService;
use Vortos\FeatureFlags\Guardrail\MetricSource\InMemoryGuardrailMetricSource;
use Vortos\FeatureFlags\Guardrail\Storage\GuardrailPolicyStorageInterface;
use Vortos\FeatureFlags\RolloutSchedule;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

final class GuardrailWatcherServiceTest extends TestCase
{
    private InMemoryGuardrailMetricSource $metrics;
    private GuardrailPolicyStorageInterface $storage;
    private GuardrailWatcherService $watcher;
    private MutableClock $clock;
    /** @var object[] */
    private array $dispatched = [];
    private ?FeatureFlag $savedFlag = null;
    private FeatureFlag $flag;

    protected function setUp(): void
    {
        DomainEventLedger::discard();
        $this->dispatched = [];
        $this->clock      = new MutableClock(new \DateTimeImmutable('2026-06-22T12:00:00+00:00'));
        $this->flag       = $this->buildFlag('checkout', enabled: true, schedule: null);

        $this->metrics = new InMemoryGuardrailMetricSource();
        $this->storage = $this->inMemoryStorage();

        $flagStorage = $this->createMock(FlagStorageInterface::class);
        $flagStorage->method('findByName')->willReturnCallback(fn() => $this->flag);
        $flagStorage->method('save')->willReturnCallback(function (FeatureFlag $f): void {
            $this->savedFlag = $f;
            $this->flag      = $f;
        });

        $uow = $this->createMock(UnitOfWorkInterface::class);
        $uow->method('run')->willReturnCallback(static fn(callable $work) => $work());

        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->method('dispatch')->willReturnCallback(function (EventEnvelope $e): void {
            $this->dispatched[] = $e->payload;
        });

        $writeService = new FlagWriteService(storage: $flagStorage, unitOfWork: $uow, eventBus: $eventBus);

        $this->watcher = new GuardrailWatcherService(
            storage:            $this->storage,
            conditionEvaluator: new GuardrailConditionEvaluator($this->metrics),
            metricSource:       $this->metrics,
            writeService:       $writeService,
            flagStorage:        $flagStorage,
            scopeContext:       new FlagScopeContext(),
            eventBus:           $eventBus,
            clock:              $this->clock,
        );
    }

    protected function tearDown(): void
    {
        DomainEventLedger::discard();
    }

    public function test_single_breach_below_quorum_does_not_trigger(): void
    {
        $this->storage->save($this->policy(windows: 2));
        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.10);

        $this->watcher->evaluate();

        $policy = $this->only();
        $this->assertSame(GuardrailStatus::Watching->value, $policy->status);
        $this->assertSame(1, $policy->consecutiveBreachCount);
        $this->assertNull($this->savedFlag);
    }

    public function test_consecutive_breaches_trigger_disable(): void
    {
        $this->storage->save($this->policy(windows: 2, action: GuardrailAction::Disable));
        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.10);

        $this->watcher->evaluate();
        $this->watcher->evaluate();

        $policy = $this->only();
        $this->assertSame(GuardrailStatus::Triggered->value, $policy->status);
        $this->assertNotNull($this->savedFlag);
        $this->assertFalse($this->savedFlag->enabled);
        $this->assertInstanceOf(GuardrailTriggeredEvent::class, $this->lastOf(GuardrailTriggeredEvent::class));
    }

    public function test_breach_then_clear_resets_counter(): void
    {
        $this->storage->save($this->policy(windows: 3));
        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.10);
        $this->watcher->evaluate();
        $this->assertSame(1, $this->only()->consecutiveBreachCount);

        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.01);
        $this->watcher->evaluate();

        $this->assertSame(0, $this->only()->consecutiveBreachCount);
        $this->assertSame(GuardrailStatus::Watching->value, $this->only()->status);
    }

    public function test_unknown_metric_does_not_change_counter(): void
    {
        $this->storage->save($this->policy(windows: 2));
        // no metric set → unknown

        $this->watcher->evaluate();

        $this->assertSame(0, $this->only()->consecutiveBreachCount);
        $this->assertNull($this->savedFlag);
    }

    public function test_cooldown_prevents_immediate_re_evaluation(): void
    {
        $this->storage->save($this->policy(windows: 1, cooldown: 600));
        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.10);

        $this->watcher->evaluate(); // triggers (windows=1)
        $this->assertSame(GuardrailStatus::Triggered->value, $this->only()->status);

        // Clear the metric but stay within cooldown → must NOT resolve yet.
        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.01);
        $this->watcher->evaluate();

        $this->assertSame(GuardrailStatus::Triggered->value, $this->only()->status);
    }

    public function test_resolves_after_cooldown_when_metric_recovers(): void
    {
        $this->storage->save($this->policy(windows: 1, cooldown: 600));
        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.10);
        $this->watcher->evaluate(); // triggers

        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.01);
        $this->clock->advance(601);
        $this->watcher->evaluate();

        $this->assertSame(GuardrailStatus::Resolved->value, $this->only()->status);
        $this->assertInstanceOf(GuardrailResolvedEvent::class, $this->lastOf(GuardrailResolvedEvent::class));
    }

    public function test_pause_ramp_action_freezes_schedule(): void
    {
        $now      = $this->clock->now();
        $schedule = new RolloutSchedule(
            enableAt: $now->modify('-1 hour'),
            disableAt: null,
            stops: [
                ['at' => $now->modify('-1 hour'), 'percentage' => 0],
                ['at' => $now->modify('+1 hour'), 'percentage' => 100],
            ],
        );
        $this->flag = $this->buildFlag('checkout', enabled: true, schedule: $schedule);

        $this->storage->save($this->policy(windows: 1, action: GuardrailAction::PauseRamp, pauseTarget: 25));
        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.10);

        $this->watcher->evaluate();

        $this->assertNotNull($this->savedFlag);
        $this->assertNotNull($this->savedFlag->schedule);
        $this->assertCount(1, $this->savedFlag->schedule->stops);
        $this->assertSame(25, $this->savedFlag->schedule->stops[0]['percentage']);
    }

    public function test_pause_ramp_without_schedule_disables(): void
    {
        $this->flag = $this->buildFlag('checkout', enabled: true, schedule: null);
        $this->storage->save($this->policy(windows: 1, action: GuardrailAction::PauseRamp, pauseTarget: null));
        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.10);

        $this->watcher->evaluate();

        $this->assertNotNull($this->savedFlag);
        $this->assertFalse($this->savedFlag->enabled);
    }

    public function test_disable_action_uses_system_guardrail_actor(): void
    {
        $this->storage->save($this->policy(windows: 1, action: GuardrailAction::Disable));
        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.10);

        $this->watcher->evaluate();

        $triggered = $this->lastOf(GuardrailTriggeredEvent::class);
        $this->assertInstanceOf(GuardrailTriggeredEvent::class, $triggered);
        $this->assertSame('disable', $triggered->action);
    }

    private function policy(
        int $windows = 2,
        GuardrailAction $action = GuardrailAction::Disable,
        int $cooldown = 600,
        ?int $pauseTarget = null,
    ): GuardrailPolicy {
        return new GuardrailPolicy(
            id: 'gp-1', flagName: 'checkout', projectId: 'default', environment: 'production',
            status: GuardrailStatus::Watching->value, action: $action, pauseRampTargetPct: $pauseTarget,
            consecutiveWindows: $windows, windowSeconds: 300, cooldownSeconds: $cooldown,
            enabled: true, consecutiveBreachCount: 0, lastEvaluatedAt: null, triggeredAt: null, resolvedAt: null,
            createdAt: $this->clock->now(), createdBy: 'admin',
            conditions: [
                new GuardrailCondition(
                    id: 'c-1', combinator: null, metricKind: GuardrailMetricKind::ErrorRate,
                    customMetricName: null, threshold: 0.05, comparisonOperator: 'gt', sortOrder: 0,
                ),
            ],
        );
    }

    private function only(): GuardrailPolicy
    {
        return $this->storage->findById('gp-1');
    }

    private function lastOf(string $class): ?object
    {
        $matches = array_values(array_filter($this->dispatched, static fn(object $e) => $e instanceof $class));

        return $matches === [] ? null : end($matches);
    }

    private function buildFlag(string $name, bool $enabled, ?RolloutSchedule $schedule): FeatureFlag
    {
        $now = new \DateTimeImmutable();
        return new FeatureFlag(
            id: '11111111-1111-4111-8111-111111111111', name: $name, description: '', enabled: $enabled,
            rules: [], variants: null, createdAt: $now, updatedAt: $now, schedule: $schedule,
        );
    }

    private function inMemoryStorage(): GuardrailPolicyStorageInterface
    {
        return new class implements GuardrailPolicyStorageInterface {
            /** @var array<string, GuardrailPolicy> */
            private array $rows = [];

            public function save(GuardrailPolicy $policy): void
            {
                $this->rows[$policy->id] = $policy;
            }

            public function findById(string $id): ?GuardrailPolicy
            {
                return $this->rows[$id] ?? null;
            }

            public function findEnabled(string $projectId, string $environment): array
            {
                return array_values(array_filter($this->rows, static fn(GuardrailPolicy $p) => $p->enabled));
            }

            public function findDueForEvaluation(\DateTimeImmutable $before, int $limit): array
            {
                return array_values(array_filter($this->rows, static fn(GuardrailPolicy $p) => $p->enabled));
            }

            public function delete(string $id): void
            {
                unset($this->rows[$id]);
            }
        };
    }
}
