<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\ChangeRequest;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Vortos\Domain\Event\DomainEventLedger;
use Vortos\FeatureFlags\Application\FlagPromotionService;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequest;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestPolicy;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestProcessor;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestService;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestStatus;
use Vortos\FeatureFlags\ChangeRequest\ChangeType;
use Vortos\FeatureFlags\ChangeRequest\Storage\ChangeRequestStorageInterface;
use Vortos\FeatureFlags\ChangeRequest\Storage\EnvironmentProtectionStorageInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Storage\FlagEnvironmentStateStorageInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

final class ChangeRequestProcessorTest extends TestCase
{
    private \DateTimeImmutable $now;
    private ChangeRequestStorageInterface $storage;
    private ChangeRequestService $service;
    private ChangeRequestProcessor $processor;
    private ?FeatureFlag $savedFlag = null;

    protected function setUp(): void
    {
        DomainEventLedger::discard();
        $this->now = new \DateTimeImmutable('2026-06-22T12:00:00+00:00');

        $clock = $this->clockAt($this->now);

        $flag = $this->buildFlag('checkout', enabled: false);
        $flagStorage = $this->createMock(FlagStorageInterface::class);
        $flagStorage->method('findByName')->willReturnCallback(fn() => $flag);
        $flagStorage->method('save')->willReturnCallback(function (FeatureFlag $f): void {
            $this->savedFlag = $f;
        });

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
            storage: $this->storage, policy: new ChangeRequestPolicy($protection),
            writeService: $writeService, promotionService: $promotion,
            unitOfWork: $uow, eventBus: $eventBus, scopeContext: new FlagScopeContext(), clock: $clock,
        );

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(1);

        $this->processor = new ChangeRequestProcessor(
            storage: $this->storage, service: $this->service, connection: $connection,
            unitOfWork: $uow, eventBus: $eventBus, clock: $clock,
        );
    }

    protected function tearDown(): void
    {
        DomainEventLedger::discard();
    }

    public function test_process_scheduled_applies_due_approved_requests(): void
    {
        $cr = $this->approved(ChangeType::Enable, applyAt: $this->now->modify('-1 minute'));

        $count = $this->processor->processScheduledApplications('system');

        $this->assertSame(1, $count);
        $this->assertSame(ChangeRequestStatus::Applied, $this->storage->findById($cr->id())->status());
        $this->assertNotNull($this->savedFlag);
        $this->assertTrue($this->savedFlag->enabled);
    }

    public function test_process_scheduled_skips_already_applied(): void
    {
        $cr = $this->approved(ChangeType::Enable, applyAt: $this->now->modify('-1 minute'));
        $this->service->apply($cr->id(), 'human');
        $this->savedFlag = null;

        $count = $this->processor->processScheduledApplications('system');

        $this->assertSame(0, $count);
        $this->assertNull($this->savedFlag);
    }

    public function test_process_expired_marks_stale_requests_expired(): void
    {
        $cr = $this->pendingExpired();

        $count = $this->processor->processExpired();

        $this->assertSame(1, $count);
        $this->assertSame(ChangeRequestStatus::Expired, $this->storage->findById($cr->id())->status());
    }

    public function test_process_expired_ignores_live_requests(): void
    {
        $this->approved(ChangeType::Enable, applyAt: null);

        $count = $this->processor->processExpired();

        $this->assertSame(0, $count);
    }

    private function approved(ChangeType $type, ?\DateTimeImmutable $applyAt): ChangeRequest
    {
        $cr = $this->service->create(
            'checkout', 'default', 'production', $type, ['reason' => 'go'],
            'apply this scheduled change', 'alice', $applyAt,
        );

        return $this->service->vote($cr->id(), 'bob', true, 'approved');
    }

    private function pendingExpired(): ChangeRequest
    {
        $cr = new ChangeRequest(
            id: 'cr-expired', flagName: 'checkout', projectId: 'default', environment: 'production',
            changeType: ChangeType::Enable, payload: [], reason: 'expired request here',
            requestedBy: 'alice', requestedAt: $this->now->modify('-8 days'),
            status: ChangeRequestStatus::Pending, requiredApprovals: 1, approvals: [], rejections: [],
            applyAt: null, expiresAt: $this->now->modify('-1 day'),
        );
        $this->storage->save($cr);

        return $cr;
    }

    private function buildFlag(string $name, bool $enabled): FeatureFlag
    {
        $now = new \DateTimeImmutable();
        return new FeatureFlag(
            id: '11111111-1111-4111-8111-111111111111', name: $name, description: '', enabled: $enabled,
            rules: [], variants: null, createdAt: $now, updatedAt: $now,
        );
    }

    private function clockAt(\DateTimeImmutable $at): ClockInterface
    {
        return new class($at) implements ClockInterface {
            public function __construct(private \DateTimeImmutable $at) {}
            public function now(): \DateTimeImmutable
            {
                return $this->at;
            }
        };
    }

    private function inMemoryStorage(): ChangeRequestStorageInterface
    {
        return new class($this->now) implements ChangeRequestStorageInterface {
            /** @var array<string, ChangeRequest> */
            private array $rows = [];

            public function __construct(private \DateTimeImmutable $now) {}

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
                return array_values(array_filter($this->rows, function (ChangeRequest $r): bool {
                    return $r->status() === ChangeRequestStatus::Approved
                        && ($r->applyAt() === null || $r->applyAt() <= $this->now);
                }));
            }

            public function findExpired(): array
            {
                return array_values(array_filter($this->rows, function (ChangeRequest $r): bool {
                    return in_array($r->status(), [ChangeRequestStatus::Pending, ChangeRequestStatus::Approved], true)
                        && $r->expiresAt() <= $this->now;
                }));
            }

            public function findByFlag(string $flagName, string $projectId, string $environment, ?ChangeRequestStatus $status = null, ?string $afterCursor = null, int $limit = 0): array
            {
                return array_values($this->rows);
            }
        };
    }
}
