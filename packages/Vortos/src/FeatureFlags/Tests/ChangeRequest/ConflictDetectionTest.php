<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\ChangeRequest;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Vortos\Domain\Event\DomainEventLedger;
use Vortos\FeatureFlags\Application\FlagPromotionService;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequest;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestConflictException;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestPolicy;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestService;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestStatus;
use Vortos\FeatureFlags\ChangeRequest\ChangeType;
use Vortos\FeatureFlags\ChangeRequest\Storage\ChangeRequestStorageInterface;
use Vortos\FeatureFlags\ChangeRequest\Storage\EnvironmentProtectionStorageInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Storage\FlagEnvironmentStateStorageInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Http\Exception\HttpException;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

final class ConflictDetectionTest extends TestCase
{
    private ChangeRequestService $service;
    private ChangeRequestStorageInterface $storage;
    private \DateTimeImmutable $t0;

    protected function setUp(): void
    {
        DomainEventLedger::discard();

        $this->t0 = new \DateTimeImmutable('2026-06-22T10:00:00+00:00');

        $flag = new FeatureFlag(
            id: '11111111-1111-4111-8111-111111111111', name: 'checkout', description: '', enabled: false,
            rules: [], variants: null, createdAt: $this->t0, updatedAt: $this->t0,
        );
        $flagStorage = $this->createMock(FlagStorageInterface::class);
        $flagStorage->method('findByName')->willReturn($flag);
        $flagStorage->method('save');

        $uow = $this->createMock(UnitOfWorkInterface::class);
        $uow->method('run')->willReturnCallback(static fn(callable $work) => $work());

        $eventBus = $this->createMock(EventBusInterface::class);
        $envStateStorage = $this->createMock(FlagEnvironmentStateStorageInterface::class);
        $envStateStorage->method('findForFlag')->willReturn(null);

        $writeService = new FlagWriteService(storage: $flagStorage, unitOfWork: $uow, eventBus: $eventBus);
        $promotion    = new FlagPromotionService(storage: $flagStorage, envStateStorage: $envStateStorage, unitOfWork: $uow, eventBus: $eventBus);

        $protection = $this->createMock(EnvironmentProtectionStorageInterface::class);
        $protection->method('findForEnvironment')->willReturn(null);

        $this->storage = $this->inMemoryStorage();

        $this->service = new ChangeRequestService(
            storage:          $this->storage,
            policy:           new ChangeRequestPolicy($protection),
            writeService:     $writeService,
            promotionService: $promotion,
            unitOfWork:       $uow,
            eventBus:         $eventBus,
            scopeContext:     new FlagScopeContext(),
            clock:            $this->clockAt($this->t0),
        );
    }

    protected function tearDown(): void
    {
        DomainEventLedger::discard();
    }

    public function test_no_conflict_when_no_competing_applies(): void
    {
        $cr = $this->createAndApprove(ChangeType::Enable);

        $applied = $this->service->apply($cr->id(), 'deployer');

        $this->assertSame(ChangeRequestStatus::Applied, $applied->status());
    }

    public function test_conflict_detected_when_competing_cr_applied_after_creation(): void
    {
        // CR-A: created at T0
        $crA = $this->createAndApprove(ChangeType::Enable, requestedBy: 'alice');

        // CR-B: created at T0+1s, approved, applied at T0+2s — competes
        $t1 = $this->t0->modify('+1 second');
        $t2 = $this->t0->modify('+2 seconds');

        $crB = ChangeRequest::create(
            id: 'cr-b', flagName: 'checkout', projectId: 'default', environment: 'production',
            changeType: ChangeType::Enable, payload: [], reason: 'competing change here',
            requestedBy: 'bob', requestedAt: $t1,
            requiredApprovals: 1, expiresAt: $t1->modify('+7 days'),
        );
        $crB->addApproval('alice', 'ok', $t1);
        $crB->markApplied('alice', $t2);
        $this->storage->save($crB);

        // Now try to apply CR-A → conflict
        $this->expectException(ChangeRequestConflictException::class);
        $this->service->apply($crA->id(), 'deployer');
    }

    public function test_no_conflict_when_competing_cr_applied_before_creation(): void
    {
        // Old CR applied at T0-1h
        $oldAt = $this->t0->modify('-1 hour');
        $oldCr = ChangeRequest::create(
            id: 'cr-old', flagName: 'checkout', projectId: 'default', environment: 'production',
            changeType: ChangeType::Enable, payload: [], reason: 'old change applied before',
            requestedBy: 'alice', requestedAt: $oldAt,
            requiredApprovals: 1, expiresAt: $oldAt->modify('+7 days'),
        );
        $oldCr->addApproval('bob', 'ok', $oldAt);
        $oldCr->markApplied('bob', $oldAt->modify('+10 minutes'));
        $this->storage->save($oldCr);

        // New CR created at T0 — the old CR was applied before creation, not a conflict
        $crNew = $this->createAndApprove(ChangeType::Disable, requestedBy: 'carol');
        $applied = $this->service->apply($crNew->id(), 'deployer');

        $this->assertSame(ChangeRequestStatus::Applied, $applied->status());
    }

    public function test_conflict_exception_carries_competing_ids(): void
    {
        $crA = $this->createAndApprove(ChangeType::Enable, requestedBy: 'alice');

        $t1 = $this->t0->modify('+1 second');
        $crB = ChangeRequest::create(
            id: 'cr-competitor', flagName: 'checkout', projectId: 'default', environment: 'production',
            changeType: ChangeType::Enable, payload: [], reason: 'competing change here',
            requestedBy: 'bob', requestedAt: $t1,
            requiredApprovals: 1, expiresAt: $t1->modify('+7 days'),
        );
        $crB->addApproval('carol', 'ok', $t1);
        $crB->markApplied('carol', $t1->modify('+5 seconds'));
        $this->storage->save($crB);

        try {
            $this->service->apply($crA->id(), 'deployer');
            $this->fail('Expected ChangeRequestConflictException');
        } catch (ChangeRequestConflictException $e) {
            $this->assertContains('cr-competitor', $e->conflictingIds);
        }
    }

    private function createAndApprove(ChangeType $type, string $requestedBy = 'alice'): ChangeRequest
    {
        $cr = $this->service->create(
            'checkout', 'default', 'production', $type,
            ['reason' => 'deploy'], 'apply this change now', $requestedBy,
        );

        return $this->service->vote($cr->id(), 'bob', true, 'looks good');
    }

    private function clockAt(\DateTimeImmutable $at): ClockInterface
    {
        return new class($at) implements ClockInterface {
            public function __construct(private \DateTimeImmutable $at) {}
            public function now(): \DateTimeImmutable { return $this->at; }
        };
    }

    private function inMemoryStorage(): ChangeRequestStorageInterface
    {
        return new class implements ChangeRequestStorageInterface {
            /** @var array<string, ChangeRequest> */
            private array $rows = [];

            public function save(ChangeRequest $request): void
            {
                $this->rows[$request->id()] = $request;
            }

            public function findById(string $id): ?ChangeRequest
            {
                return $this->rows[$id] ?? null;
            }

            public function findDueForApplication(): array
            {
                return array_values(array_filter(
                    $this->rows,
                    static fn(ChangeRequest $r) => $r->status() === ChangeRequestStatus::Approved,
                ));
            }

            public function findExpired(): array
            {
                return [];
            }

            public function findByFlag(
                string $flagName,
                string $projectId,
                string $environment,
                ?ChangeRequestStatus $status = null,
                ?string $afterCursor = null,
                int $limit = 0,
            ): array {
                return array_values(array_filter(
                    $this->rows,
                    static fn(ChangeRequest $r) => $status === null || $r->status() === $status,
                ));
            }
        };
    }
}
