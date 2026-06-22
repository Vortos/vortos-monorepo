<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Http\Management;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Validator\Validation;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Cqrs\Validation\ValidationException;
use Vortos\Cqrs\Validation\VortosValidator;
use Vortos\Domain\Event\DomainEventLedger;
use Vortos\FeatureFlags\Application\FlagPromotionService;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequest;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestPolicy;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestService;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestStatus;
use Vortos\FeatureFlags\ChangeRequest\ChangeType;
use Vortos\FeatureFlags\ChangeRequest\Storage\ChangeRequestStorageInterface;
use Vortos\FeatureFlags\ChangeRequest\Storage\EnvironmentProtectionStorageInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Http\Management\ChangeRequestController;
use Vortos\FeatureFlags\Http\Management\ManagementResponseFactory;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\Storage\FlagEnvironmentStateStorageInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\HttpException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

final class ChangeRequestControllerTest extends TestCase
{
    private ManagementAuthzGateInterface $authz;
    private FlagRateLimitService $rateLimit;
    private ChangeRequestStorageInterface $storage;
    private ChangeRequestController $controller;

    protected function setUp(): void
    {
        DomainEventLedger::discard();

        $this->authz     = $this->createMock(ManagementAuthzGateInterface::class);
        $this->rateLimit = $this->createMock(FlagRateLimitService::class);

        $flag = new FeatureFlag(
            id: '11111111-1111-4111-8111-111111111111', name: 'checkout', description: '', enabled: false,
            rules: [], variants: null, createdAt: new \DateTimeImmutable(), updatedAt: new \DateTimeImmutable(),
        );
        $flagStorage = $this->createMock(FlagStorageInterface::class);
        $flagStorage->method('findByName')->willReturnCallback(fn() => $flag);
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

        $service = new ChangeRequestService(
            storage: $this->storage, policy: new ChangeRequestPolicy($protection),
            writeService: $writeService, promotionService: $promotion,
            unitOfWork: $uow, eventBus: $eventBus, scopeContext: new FlagScopeContext(),
            clock: $this->clockAt('2026-06-22T10:00:00+00:00'),
        );

        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('test-user', ['ROLE_ADMIN']));
        $currentUser = new CurrentUserProvider($adapter);

        $validator = new VortosValidator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());

        $this->controller = new ChangeRequestController(
            service: $service, storage: $this->storage, authz: $this->authz, rateLimit: $this->rateLimit,
            response: new ManagementResponseFactory(), currentUser: $currentUser, validator: $validator,
        );
    }

    protected function tearDown(): void
    {
        DomainEventLedger::discard();
    }

    public function test_create_requires_flags_write_any(): void
    {
        $this->authz->method('requirePermission')->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->create($this->jsonRequest([]));
    }

    public function test_create_rejects_short_reason(): void
    {
        $this->authz->method('requirePermission');

        $this->expectException(ValidationException::class);
        $this->controller->create($this->jsonRequest([
            'flagName' => 'checkout', 'projectId' => 'default', 'environment' => 'production',
            'changeType' => 'enable', 'reason' => 'short',
        ]));
    }

    public function test_create_returns_201(): void
    {
        $this->authz->method('requirePermission');

        $response = $this->controller->create($this->jsonRequest([
            'flagName' => 'checkout', 'projectId' => 'default', 'environment' => 'production',
            'changeType' => 'enable', 'reason' => 'launch the checkout flow now',
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('pending', $body['data']['status']);
    }

    public function test_list_requires_flags_read_any(): void
    {
        $this->authz->method('requirePermission')->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->list($this->queryRequest(['flagName' => 'checkout', 'environment' => 'production']));
    }

    public function test_list_requires_flag_and_env_params(): void
    {
        $this->authz->method('requirePermission');

        $this->expectException(HttpException::class);
        $this->controller->list($this->queryRequest([]));
    }

    public function test_list_returns_items(): void
    {
        $this->authz->method('requirePermission');
        $this->seedPending('alice');

        $response = $this->controller->list($this->queryRequest(['flagName' => 'checkout', 'projectId' => 'default', 'environment' => 'production']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->decode($response)['data']);
    }

    public function test_vote_requires_flags_approve_any(): void
    {
        $this->authz->method('requirePermission')->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->vote('cr-1', $this->jsonRequest(['approve' => true, 'reason' => 'ok']));
    }

    public function test_vote_approve_returns_200(): void
    {
        $this->authz->method('requirePermission');
        $cr = $this->seedPending('alice');

        $response = $this->controller->vote($cr->id(), $this->jsonRequest(['approve' => true, 'reason' => 'reviewed']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('approved', $this->decode($response)['data']['status']);
    }

    public function test_self_approve_returns_422(): void
    {
        $this->authz->method('requirePermission');
        $cr = $this->seedPending('test-user');

        try {
            $this->controller->vote($cr->id(), $this->jsonRequest(['approve' => true, 'reason' => 'self']));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_cancel_returns_200(): void
    {
        $this->authz->method('requirePermission');
        $cr = $this->seedPending('alice');

        $response = $this->controller->cancel($cr->id());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('cancelled', $this->decode($response)['data']['status']);
    }

    public function test_apply_requires_flags_publish_any(): void
    {
        $this->authz->method('requirePermission')->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->apply('cr-1');
    }

    public function test_apply_approved_returns_200(): void
    {
        $this->authz->method('requirePermission');
        $cr = $this->seedApproved();

        $response = $this->controller->apply($cr->id());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('applied', $this->decode($response)['data']['status']);
    }

    public function test_show_returns_404_for_missing(): void
    {
        $this->authz->method('requirePermission');

        $this->expectException(NotFoundException::class);
        $this->controller->show('ghost');
    }

    private function seedPending(string $requestedBy): ChangeRequest
    {
        $now = new \DateTimeImmutable('2026-06-22T10:00:00+00:00');
        $cr  = ChangeRequest::create(
            id: 'cr-' . bin2hex(random_bytes(4)), flagName: 'checkout', projectId: 'default', environment: 'production',
            changeType: ChangeType::Enable, payload: ['reason' => 'go'], reason: 'launch the checkout flow',
            requestedBy: $requestedBy, requestedAt: $now, requiredApprovals: 1, expiresAt: $now->modify('+7 days'),
        );
        $this->storage->save($cr);

        return $cr;
    }

    private function seedApproved(): ChangeRequest
    {
        $cr = $this->seedPending('alice');
        $cr->addApproval('bob', 'approved', new \DateTimeImmutable('2026-06-22T10:00:00+00:00'));
        $this->storage->save($cr);

        return $cr;
    }

    private function jsonRequest(array $data): Request
    {
        return Request::create('/api/management/v1/change-requests', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($data));
    }

    private function queryRequest(array $query): Request
    {
        return Request::create('/api/management/v1/change-requests', 'GET', $query);
    }

    private function decode(object $response): array
    {
        return json_decode($response->getContent(), true);
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
                return array_values($this->rows);
            }

            public function findExpired(): array
            {
                return [];
            }

            public function findByFlag(string $flagName, string $projectId, string $environment, ?ChangeRequestStatus $status = null, ?string $afterCursor = null, int $limit = 0): array
            {
                return array_values(array_filter(
                    $this->rows,
                    static fn(ChangeRequest $r) => $r->flagName() === $flagName && ($status === null || $r->status() === $status),
                ));
            }
        };
    }
}
