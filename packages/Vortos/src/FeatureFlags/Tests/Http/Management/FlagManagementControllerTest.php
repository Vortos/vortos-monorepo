<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Http\Management;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Cqrs\Validation\VortosValidator;
use Vortos\Domain\Event\DomainEventLedger;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\FeatureFlags\Application\FlagPromotionService;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\FlagEnvironmentState;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Http\Management\FlagManagementController;
use Vortos\FeatureFlags\Http\Management\Interceptor\NullChangeRequestInterceptor;
use Vortos\FeatureFlags\Http\Management\ManagementResponseFactory;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\Storage\FlagEnvironmentStateStorageInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

final class FlagManagementControllerTest extends TestCase
{
    private FlagStorageInterface $storage;
    private ManagementAuthzGateInterface $authz;
    private FlagRateLimitService $rateLimit;
    private FlagManagementController $controller;
    private FlagWriteService $writeService;
    private FlagPromotionService $promotionService;

    protected function setUp(): void
    {
        DomainEventLedger::discard();

        $this->storage   = $this->createMock(FlagStorageInterface::class);
        $this->authz     = $this->createMock(ManagementAuthzGateInterface::class);
        $this->rateLimit = $this->createMock(FlagRateLimitService::class);

        $uow = $this->createMock(UnitOfWorkInterface::class);
        $uow->method('run')->willReturnCallback(static fn(callable $work) => $work());

        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->method('dispatch');

        $envStateStorage = $this->createMock(FlagEnvironmentStateStorageInterface::class);
        $envStateStorage->method('findForFlag')->willReturn(null);
        $envStateStorage->method('save');

        $this->writeService = new FlagWriteService(
            storage:        $this->storage,
            unitOfWork:     $uow,
            eventBus:       $eventBus,
            envStateStorage: $envStateStorage,
        );

        $this->promotionService = new FlagPromotionService(
            storage:        $this->storage,
            envStateStorage: $envStateStorage,
            unitOfWork:     $uow,
            eventBus:       $eventBus,
        );

        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('test-user', ['ROLE_ADMIN']));
        $currentUser = new CurrentUserProvider($adapter);

        $validator = new VortosValidator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());

        $this->controller = new FlagManagementController(
            storage:                    $this->storage,
            writeService:               $this->writeService,
            promotionService:           $this->promotionService,
            authz:                      $this->authz,
            rateLimit:                  $this->rateLimit,
            response:                   new ManagementResponseFactory(),
            currentUser:                $currentUser,
            scopeContext:               new FlagScopeContext(),
            projectContext:             new ProjectContext(),
            changeRequestInterceptor:   new NullChangeRequestInterceptor(),
            validator:                  $validator,
        );
    }

    protected function tearDown(): void
    {
        DomainEventLedger::discard();
    }

    public function test_list_returns_403_without_flags_read_any(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->list(new Request());
    }

    public function test_list_returns_200_with_flags(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->method('findAll')->willReturn([$this->buildFlag('dark-mode')]);

        $response = $this->controller->list(new Request());
        $body     = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['data']);
        $this->assertSame('dark-mode', $body['data'][0]['name']);
    }

    public function test_create_returns_403_without_flags_write_any(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->create(new Request());
    }

    public function test_create_returns_422_with_invalid_name(): void
    {
        $this->authz->method('requirePermission');

        $request = $this->jsonRequest(['name' => 'MY_INVALID_FLAG', 'projectId' => 'default']);

        $this->expectException(\Vortos\Cqrs\Validation\ValidationException::class);
        $this->controller->create($request);
    }

    public function test_create_returns_201_with_valid_body(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->method('findByName')->willReturn(null);
        $this->storage->method('save');

        $request  = $this->jsonRequest(['name' => 'my-flag', 'projectId' => 'default']);
        $response = $this->controller->create($request);

        $this->assertSame(201, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('my-flag', $body['data']['name']);
    }

    public function test_show_returns_404_for_nonexistent_flag(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->method('findByName')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->controller->show('nonexistent');
    }

    public function test_show_returns_200_for_existing_flag(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->method('findByName')->willReturn($this->buildFlag('my-flag'));

        $response = $this->controller->show('my-flag');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_delete_archives_flag_and_returns_204(): void
    {
        $this->authz->method('requirePermission');
        $flag = $this->buildFlag('my-flag');
        $this->storage->method('findByName')->willReturn($flag);
        $this->storage->method('save');

        $response = $this->controller->delete('my-flag');
        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_delete_returns_403_without_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->delete('my-flag');
    }

    public function test_enable_returns_403_without_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->enable('my-flag');
    }

    public function test_enable_returns_200_on_success(): void
    {
        $this->authz->method('requirePermission');
        $flag = $this->buildFlag('my-flag');
        $this->storage->method('findByName')->willReturn($flag);
        $this->storage->method('save');

        $response = $this->controller->enable('my-flag');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_disable_returns_403_without_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->disable('my-flag');
    }

    public function test_disable_returns_200_on_success(): void
    {
        $this->authz->method('requirePermission');
        $flag = $this->buildFlag('my-flag');
        $this->storage->method('findByName')->willReturn($flag);
        $this->storage->method('save');

        $response = $this->controller->disable('my-flag');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_show_nonexistent_flag_returns_404(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->method('findByName')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->controller->show('ghost-flag');
    }

    public function test_update_is_metadata_only_and_never_enables(): void
    {
        // A metadata PATCH with no applicable field must NOT flip the flag on — enabling is a
        // distinct transition with its own endpoint. buildFlag() is disabled; the echoed state
        // must stay disabled.
        $this->authz->method('requirePermission');
        $this->storage->method('findByName')->willReturn($this->buildFlag('my-flag'));

        $response = $this->controller->update('my-flag', $this->jsonRequest([]));
        $body     = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($body['data']['enabled']);
    }

    public function test_update_sets_owner_when_provided(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->method('findByName')->willReturn($this->buildFlag('my-flag'));
        $this->storage->method('save');

        $response = $this->controller->update('my-flag', $this->jsonRequest(['owner' => 'team-growth']));
        $body     = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('team-growth', $body['data']['owner']);
        $this->assertFalse($body['data']['enabled']);
    }

    public function test_serialize_exposes_targeting_config(): void
    {
        $this->authz->method('requirePermission');
        $now  = new \DateTimeImmutable();
        $flag = new FeatureFlag(
            id: '22222222-2222-4222-8222-222222222222', name: 'exp', description: '', enabled: true,
            rules: [new FlagRule(type: FlagRule::TYPE_PERCENTAGE, percentage: 25)],
            variants: ['control' => 50, 'treatment' => 50], createdAt: $now, updatedAt: $now,
        );
        $this->storage->method('findByName')->willReturn($flag);

        $body = $this->decode($this->controller->show('exp'));

        $this->assertCount(1, $body['data']['rules']);
        $this->assertSame(25, $body['data']['rules'][0]['percentage']);
        $this->assertSame(['control' => 50, 'treatment' => 50], $body['data']['variants']);
        $this->assertArrayHasKey('schedule', $body['data']);
        $this->assertArrayHasKey('defaultValue', $body['data']);
    }

    private function buildFlag(string $name): FeatureFlag
    {
        $now = new \DateTimeImmutable();
        return new FeatureFlag(
            id: '11111111-1111-4111-8111-111111111111', name: $name, description: '', enabled: false,
            rules: [], variants: null, createdAt: $now, updatedAt: $now,
        );
    }

    private function jsonRequest(array $data): Request
    {
        return Request::create('/api/management/v1/flags', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($data));
    }

    private function decode(object $response): array
    {
        return json_decode($response->getContent(), true);
    }
}
