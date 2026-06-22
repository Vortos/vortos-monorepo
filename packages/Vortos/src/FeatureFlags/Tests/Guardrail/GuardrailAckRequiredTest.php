<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Guardrail;

use PHPUnit\Framework\TestCase;
use Vortos\Domain\Event\DomainEventLedger;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Guardrail\Domain\Event\GuardrailPendingAckEvent;
use Vortos\FeatureFlags\Guardrail\Domain\Event\GuardrailResolvedEvent;
use Vortos\FeatureFlags\Guardrail\GuardrailAction;
use Vortos\FeatureFlags\Guardrail\GuardrailCondition;
use Vortos\FeatureFlags\Guardrail\GuardrailConditionEvaluator;
use Vortos\FeatureFlags\Guardrail\GuardrailMetricKind;
use Vortos\FeatureFlags\Guardrail\GuardrailPolicy;
use Vortos\FeatureFlags\Guardrail\GuardrailPolicyService;
use Vortos\FeatureFlags\Guardrail\GuardrailStatus;
use Vortos\FeatureFlags\Guardrail\GuardrailWatcherService;
use Vortos\FeatureFlags\Guardrail\MetricSource\InMemoryGuardrailMetricSource;
use Vortos\FeatureFlags\Guardrail\Storage\GuardrailPolicyStorageInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

final class GuardrailAckRequiredTest extends TestCase
{
    private InMemoryGuardrailMetricSource $metrics;
    private GuardrailWatcherService $watcher;
    private GuardrailPolicyService $policyService;
    private GuardrailPolicyStorageInterface $storage;
    private MutableClock $clock;
    /** @var object[] */
    private array $dispatched = [];

    protected function setUp(): void
    {
        DomainEventLedger::discard();
        $this->dispatched = [];
        $this->clock      = new MutableClock(new \DateTimeImmutable('2026-06-22T12:00:00+00:00'));
        $this->metrics    = new InMemoryGuardrailMetricSource();

        $flag = new FeatureFlag(
            id: '11111111-1111-4111-8111-111111111111', name: 'checkout', description: '', enabled: true, rules: [], variants: null,
            createdAt: new \DateTimeImmutable(), updatedAt: new \DateTimeImmutable(),
        );
        $flagStorage = $this->createMock(FlagStorageInterface::class);
        $flagStorage->method('findByName')->willReturnCallback(fn() => $flag);
        $flagStorage->method('save');

        $uow = $this->createMock(UnitOfWorkInterface::class);
        $uow->method('run')->willReturnCallback(static fn(callable $w) => $w());

        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->method('dispatch')->willReturnCallback(function (EventEnvelope $e): void {
            $this->dispatched[] = $e->payload;
        });

        $writeService = new FlagWriteService(storage: $flagStorage, unitOfWork: $uow, eventBus: $eventBus);

        $this->storage = $this->inMemoryStorage();

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

        $this->policyService = new GuardrailPolicyService($this->storage, $eventBus, $this->clock);
    }

    protected function tearDown(): void
    {
        DomainEventLedger::discard();
    }

    public function test_ack_required_does_not_auto_resolve_when_metric_clears(): void
    {
        $this->storage->save($this->policy(ackRequired: true));
        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.20);

        $this->watcher->evaluate(); // trigger (windows=1)
        $this->assertSame(GuardrailStatus::Triggered->value, $this->storage->findById('gp')->status);

        // metric recovers
        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.01);
        $this->clock->advance(601);
        $this->watcher->evaluate(); // must NOT auto-resolve

        $this->assertSame(GuardrailStatus::Triggered->value, $this->storage->findById('gp')->status);
        $pendingAck = $this->lastOf(GuardrailPendingAckEvent::class);
        $this->assertInstanceOf(GuardrailPendingAckEvent::class, $pendingAck);
        $this->assertSame('gp', $pendingAck->policyId);
    }

    public function test_standard_policy_auto_resolves_without_ack(): void
    {
        $this->storage->save($this->policy(ackRequired: false));
        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.20);

        $this->watcher->evaluate(); // trigger
        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.01);
        $this->clock->advance(601);
        $this->watcher->evaluate(); // auto-resolves

        $this->assertSame(GuardrailStatus::Resolved->value, $this->storage->findById('gp')->status);
        $resolved = $this->lastOf(GuardrailResolvedEvent::class);
        $this->assertInstanceOf(GuardrailResolvedEvent::class, $resolved);
        $this->assertNull($resolved->acknowledgedBy);
    }

    public function test_acknowledge_transitions_triggered_policy_to_resolved(): void
    {
        $this->storage->save($this->policy(ackRequired: true));
        $this->metrics->set(GuardrailMetricKind::ErrorRate, 'checkout', 'production', 0.20);

        $this->watcher->evaluate(); // trigger
        $this->assertSame(GuardrailStatus::Triggered->value, $this->storage->findById('gp')->status);

        $resolved = $this->policyService->acknowledge('gp', 'ops-engineer');

        $this->assertSame(GuardrailStatus::Resolved->value, $resolved->status);
        $this->assertNotNull($resolved->resolvedAt);
        $event = $this->lastOf(GuardrailResolvedEvent::class);
        $this->assertInstanceOf(GuardrailResolvedEvent::class, $event);
        $this->assertSame('ops-engineer', $event->acknowledgedBy);
    }

    public function test_acknowledge_throws_for_non_triggered_policy(): void
    {
        $this->storage->save($this->policy(ackRequired: true));
        // policy is Watching, not Triggered

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/triggered/');
        $this->policyService->acknowledge('gp', 'ops-engineer');
    }

    public function test_acknowledge_throws_for_missing_policy(): void
    {
        $this->expectException(\DomainException::class);
        $this->policyService->acknowledge('ghost', 'ops-engineer');
    }

    public function test_ack_required_field_is_persisted_in_toArray(): void
    {
        $policy = $this->policy(ackRequired: true);
        $this->assertSame(true, $policy->toArray()['ack_required']);
        $rt = GuardrailPolicy::fromArray($policy->toArray());
        $this->assertTrue($rt->ackRequired);
    }

    private function policy(bool $ackRequired): GuardrailPolicy
    {
        return new GuardrailPolicy(
            id: 'gp', flagName: 'checkout', projectId: 'default', environment: 'production',
            status: GuardrailStatus::Watching->value, action: GuardrailAction::Disable, pauseRampTargetPct: null,
            consecutiveWindows: 1, windowSeconds: 300, cooldownSeconds: 600, enabled: true,
            consecutiveBreachCount: 0, lastEvaluatedAt: null, triggeredAt: null, resolvedAt: null,
            createdAt: $this->clock->now(), createdBy: 'admin',
            conditions: [new GuardrailCondition('c', null, GuardrailMetricKind::ErrorRate, null, 0.05, 'gt', 0)],
            ackRequired: $ackRequired,
        );
    }

    private function lastOf(string $class): ?object
    {
        $matches = array_values(array_filter($this->dispatched, static fn(object $e) => $e instanceof $class));

        return $matches === [] ? null : end($matches);
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
