<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\ChangeRequest;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Vortos\Domain\Event\DomainEventLedger;
use Vortos\FeatureFlags\Application\FlagPromotionService;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequest;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestPolicy;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestService;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestStatus;
use Vortos\FeatureFlags\ChangeRequest\ChangeType;
use Vortos\FeatureFlags\ChangeRequest\EnvironmentProtection;
use Vortos\FeatureFlags\ChangeRequest\Storage\ChangeRequestStorageInterface;
use Vortos\FeatureFlags\ChangeRequest\Storage\EnvironmentProtectionStorageInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Storage\FlagEnvironmentStateStorageInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

final class ChangeRequestServiceTest extends TestCase
{
    private FlagStorageInterface $flagStorage;
    private ChangeRequestStorageInterface $crStorage;
    private EnvironmentProtectionStorageInterface $protection;
    private ChangeRequestService $service;
    private ?FeatureFlag $savedFlag = null;
    private FeatureFlag $currentFlag;

    protected function setUp(): void
    {
        DomainEventLedger::discard();

        $this->currentFlag = $this->buildFlag('checkout', enabled: true);

        $this->flagStorage = $this->createMock(FlagStorageInterface::class);
        $this->flagStorage->method('findByName')->willReturnCallback(fn() => $this->currentFlag);
        $this->flagStorage->method('save')->willReturnCallback(function (FeatureFlag $f): void {
            $this->savedFlag   = $f;
            $this->currentFlag = $f;
        });

        $uow = $this->createMock(UnitOfWorkInterface::class);
        $uow->method('run')->willReturnCallback(static fn(callable $work) => $work());

        $eventBus = $this->createMock(EventBusInterface::class);

        $envStateStorage = $this->createMock(FlagEnvironmentStateStorageInterface::class);
        $envStateStorage->method('findForFlag')->willReturn(null);

        $writeService = new FlagWriteService(
            storage:    $this->flagStorage,
            unitOfWork: $uow,
            eventBus:   $eventBus,
        );
        $promotion = new FlagPromotionService(
            storage:         $this->flagStorage,
            envStateStorage: $envStateStorage,
            unitOfWork:      $uow,
            eventBus:        $eventBus,
        );

        $this->protection = $this->createMock(EnvironmentProtectionStorageInterface::class);
        $this->crStorage  = $this->inMemoryCrStorage();

        $this->service = new ChangeRequestService(
            storage:          $this->crStorage,
            policy:           new ChangeRequestPolicy($this->protection),
            writeService:     $writeService,
            promotionService: $promotion,
            unitOfWork:       $uow,
            eventBus:         $eventBus,
            scopeContext:     new FlagScopeContext(),
            clock:            $this->clockAt('2026-06-22T10:00:00+00:00'),
        );
    }

    protected function tearDown(): void
    {
        DomainEventLedger::discard();
    }

    public function test_create_stores_pending_request_with_policy_quorum(): void
    {
        $this->protection->method('findForEnvironment')->willReturn(
            new EnvironmentProtection('production', 'default', true, 2, true, 604800),
        );

        $cr = $this->service->create(
            'checkout', 'default', 'production', ChangeType::Enable,
            ['reason' => 'launch'], 'launch the checkout', 'alice',
        );

        $this->assertSame(ChangeRequestStatus::Pending, $cr->status());
        $this->assertSame(2, $cr->requiredApprovals());
        $this->assertNotNull($this->crStorage->findById($cr->id()));
    }

    public function test_vote_approve_transitions_to_approved(): void
    {
        $this->protection->method('findForEnvironment')->willReturn(null);
        $cr = $this->createPending();

        $voted = $this->service->vote($cr->id(), 'bob', true, 'reviewed');

        $this->assertSame(ChangeRequestStatus::Approved, $voted->status());
    }

    public function test_vote_reject_transitions_to_rejected(): void
    {
        $this->protection->method('findForEnvironment')->willReturn(null);
        $cr = $this->createPending();

        $voted = $this->service->vote($cr->id(), 'bob', false, 'no');

        $this->assertSame(ChangeRequestStatus::Rejected, $voted->status());
    }

    public function test_cancel_transitions_to_cancelled(): void
    {
        $this->protection->method('findForEnvironment')->willReturn(null);
        $cr = $this->createPending();

        $cancelled = $this->service->cancel($cr->id(), 'alice');

        $this->assertSame(ChangeRequestStatus::Cancelled, $cancelled->status());
    }

    public function test_apply_enable_calls_write_service(): void
    {
        $this->currentFlag = $this->buildFlag('checkout', enabled: false);
        $cr = $this->approvedRequest(ChangeType::Enable, ['reason' => 'go']);

        $applied = $this->service->apply($cr->id(), 'system');

        $this->assertSame(ChangeRequestStatus::Applied, $applied->status());
        $this->assertNotNull($this->savedFlag);
        $this->assertTrue($this->savedFlag->enabled);
    }

    public function test_apply_disable_calls_write_service(): void
    {
        $this->currentFlag = $this->buildFlag('checkout', enabled: true);
        $cr = $this->approvedRequest(ChangeType::Disable, ['reason' => 'kill']);

        $this->service->apply($cr->id(), 'system');

        $this->assertNotNull($this->savedFlag);
        $this->assertFalse($this->savedFlag->enabled);
    }

    public function test_apply_update_rules_calls_write_service(): void
    {
        $this->currentFlag = $this->buildFlag('checkout', enabled: true);
        $payload = ['rules' => [['type' => 'percentage', 'percentage' => 25]], 'reason' => 'ramp'];
        $cr = $this->approvedRequest(ChangeType::UpdateRules, $payload);

        $this->service->apply($cr->id(), 'system');

        $this->assertNotNull($this->savedFlag);
        $this->assertCount(1, $this->savedFlag->rules);
    }

    public function test_apply_rejects_unapproved_request(): void
    {
        $this->protection->method('findForEnvironment')->willReturn(null);
        $cr = $this->createPending();

        $this->expectException(\DomainException::class);
        $this->service->apply($cr->id(), 'system');
    }

    public function test_apply_create_change_type_throws(): void
    {
        $cr = $this->approvedRequest(ChangeType::Create, ['reason' => 'new flag here']);

        $this->expectException(\RuntimeException::class);
        $this->service->apply($cr->id(), 'system');
    }

    private function createPending(): ChangeRequest
    {
        return $this->service->create(
            'checkout', 'default', 'production', ChangeType::Enable,
            ['reason' => 'launch'], 'launch the checkout flow', 'alice',
        );
    }

    private function approvedRequest(ChangeType $type, array $payload): ChangeRequest
    {
        $this->protection->method('findForEnvironment')->willReturn(null);

        $cr = $this->service->create(
            'checkout', 'default', 'production', $type,
            $payload, 'apply this change now', 'alice',
        );

        return $this->service->vote($cr->id(), 'bob', true, 'approved');
    }

    private function buildFlag(string $name, bool $enabled): FeatureFlag
    {
        $now = new \DateTimeImmutable();
        return new FeatureFlag(
            id: '11111111-1111-4111-8111-111111111111', name: $name, description: '', enabled: $enabled,
            rules: [], variants: null, createdAt: $now, updatedAt: $now,
        );
    }

    private function clockAt(string $iso): ClockInterface
    {
        return new class($iso) implements ClockInterface {
            public function __construct(private string $iso) {}
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->iso);
            }
        };
    }

    private function inMemoryCrStorage(): ChangeRequestStorageInterface
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

            public function findByFlag(string $flagName, string $projectId, string $environment, ?ChangeRequestStatus $status = null, ?string $afterCursor = null, int $limit = 0): array
            {
                return array_values(array_filter(
                    $this->rows,
                    static fn(ChangeRequest $r) => $status === null || $r->status() === $status,
                ));
            }

            public function findRecent(?ChangeRequestStatus $status = null, ?string $environment = null, ?string $projectId = null, ?string $afterCursor = null, int $limit = 0): array
            {
                return array_values(array_filter($this->rows, static fn(ChangeRequest $r) => $status === null || $r->status() === $status));
            }
        };
    }
}
