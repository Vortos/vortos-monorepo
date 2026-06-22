<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Http\Management;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Validator\Validation;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;
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
use Vortos\FeatureFlags\ChangeRequest\Storage\DatabaseChangeRequestStorage;
use Vortos\FeatureFlags\ChangeRequest\Storage\EnvironmentProtectionStorageInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Http\Management\ChangeRequestController;
use Vortos\FeatureFlags\Http\Management\CursorEncoder;
use Vortos\FeatureFlags\Http\Management\ManagementResponseFactory;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\Storage\FlagEnvironmentStateStorageInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Http\Request;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;
use Doctrine\DBAL\DriverManager;

final class CursorPaginationTest extends TestCase
{
    private ChangeRequestController $controller;
    private ChangeRequestStorageInterface $storage;
    private ManagementAuthzGateInterface $authz;
    private \DateTimeImmutable $t0;

    protected function setUp(): void
    {
        DomainEventLedger::discard();
        $this->t0 = new \DateTimeImmutable('2026-06-22T10:00:00+00:00');

        $this->authz = $this->createMock(ManagementAuthzGateInterface::class);

        $flag = new FeatureFlag(
            id: '11111111-1111-4111-8111-111111111111', name: 'checkout', description: '', enabled: false,
            rules: [], variants: null, createdAt: $this->t0, updatedAt: $this->t0,
        );
        $flagStorage = $this->createMock(FlagStorageInterface::class);
        $flagStorage->method('findByName')->willReturn($flag);
        $flagStorage->method('save');

        $uow = $this->createMock(UnitOfWorkInterface::class);
        $uow->method('run')->willReturnCallback(static fn(callable $w) => $w());
        $eventBus = $this->createMock(EventBusInterface::class);
        $envStateStorage = $this->createMock(FlagEnvironmentStateStorageInterface::class);
        $envStateStorage->method('findForFlag')->willReturn(null);

        $writeService = new FlagWriteService(storage: $flagStorage, unitOfWork: $uow, eventBus: $eventBus);
        $promotion    = new FlagPromotionService(storage: $flagStorage, envStateStorage: $envStateStorage, unitOfWork: $uow, eventBus: $eventBus);
        $protection   = $this->createMock(EnvironmentProtectionStorageInterface::class);
        $protection->method('findForEnvironment')->willReturn(null);

        // Use real DBAL-backed SQLite storage so cursor SQL is tested
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement('CREATE TABLE feature_flag_change_requests (
            id TEXT PRIMARY KEY,
            flag_name TEXT NOT NULL,
            project_id TEXT NOT NULL,
            environment TEXT NOT NULL,
            change_type TEXT NOT NULL,
            payload TEXT NOT NULL,
            reason TEXT NOT NULL,
            requested_by TEXT NOT NULL,
            requested_at TEXT NOT NULL,
            status TEXT NOT NULL,
            required_approvals INTEGER NOT NULL,
            approvals TEXT NOT NULL,
            rejections TEXT NOT NULL,
            apply_at TEXT,
            expires_at TEXT NOT NULL,
            applied_at TEXT,
            applied_by TEXT
        )');

        $this->storage = new DatabaseChangeRequestStorage($conn, 'feature_flag_change_requests');

        $service = new ChangeRequestService(
            storage: $this->storage, policy: new ChangeRequestPolicy($protection),
            writeService: $writeService, promotionService: $promotion,
            unitOfWork: $uow, eventBus: $eventBus, scopeContext: new FlagScopeContext(),
            clock: $this->clockAt($this->t0),
        );

        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('test-user', ['ROLE_ADMIN']));
        $currentUser = new CurrentUserProvider($adapter);
        $validator   = new VortosValidator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
        $rateLimit   = $this->createMock(FlagRateLimitService::class);

        $this->controller = new ChangeRequestController(
            service: $service, storage: $this->storage, authz: $this->authz, rateLimit: $rateLimit,
            response: new ManagementResponseFactory(), currentUser: $currentUser, validator: $validator,
        );

        // Seed 5 change requests at different timestamps
        foreach (range(1, 5) as $i) {
            $cr = ChangeRequest::create(
                id: sprintf('cr-%02d', $i),
                flagName: 'checkout', projectId: 'default', environment: 'production',
                changeType: ChangeType::Enable,
                payload: [], reason: "change request number $i for testing",
                requestedBy: 'alice',
                requestedAt: $this->t0->modify("+{$i} seconds"),
                requiredApprovals: 1,
                expiresAt: $this->t0->modify('+7 days'),
            );
            $this->storage->save($cr);
        }
    }

    protected function tearDown(): void
    {
        DomainEventLedger::discard();
    }

    public function test_returns_all_items_with_no_cursor_when_count_le_limit(): void
    {
        $this->authz->method('requirePermission');

        $response = $this->controller->list($this->query(['limit' => '10']));
        $body     = $this->decode($response);

        $this->assertCount(5, $body['data']);
        $this->assertNull($body['pagination']['nextCursor']);
    }

    public function test_returns_next_cursor_when_more_items_exist(): void
    {
        $this->authz->method('requirePermission');

        $response = $this->controller->list($this->query(['limit' => '2']));
        $body     = $this->decode($response);

        $this->assertCount(2, $body['data']);
        $this->assertNotNull($body['pagination']['nextCursor']);
    }

    public function test_cursor_fetches_second_page(): void
    {
        $this->authz->method('requirePermission');

        // Page 1: limit 2
        $page1    = $this->decode($this->controller->list($this->query(['limit' => '2'])));
        $cursor   = $page1['pagination']['nextCursor'];
        $this->assertNotNull($cursor);
        $this->assertCount(2, $page1['data']);

        // Page 2: use cursor
        $page2 = $this->decode($this->controller->list($this->query(['limit' => '2', 'cursor' => $cursor])));
        $this->assertCount(2, $page2['data']);

        // No overlap between pages
        $page1Ids = array_column($page1['data'], 'id');
        $page2Ids = array_column($page2['data'], 'id');
        $this->assertEmpty(array_intersect($page1Ids, $page2Ids));
    }

    public function test_third_page_has_remaining_item_and_no_cursor(): void
    {
        $this->authz->method('requirePermission');

        $page1  = $this->decode($this->controller->list($this->query(['limit' => '2'])));
        $page2  = $this->decode($this->controller->list($this->query(['limit' => '2', 'cursor' => $page1['pagination']['nextCursor']])));
        $page3  = $this->decode($this->controller->list($this->query(['limit' => '2', 'cursor' => $page2['pagination']['nextCursor']])));

        $this->assertCount(1, $page3['data']);
        $this->assertNull($page3['pagination']['nextCursor']);
    }

    public function test_cursor_encoder_round_trips(): void
    {
        $id = 'cr-01';
        $at = new \DateTimeImmutable('2026-06-22T10:00:01+00:00');

        $encoded = CursorEncoder::encode($id, $at);
        $decoded = CursorEncoder::decode($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame($id, $decoded['id']);
        $this->assertSame($at->format(\DateTimeInterface::ATOM), $decoded['at']);
    }

    public function test_invalid_cursor_is_ignored_and_returns_from_beginning(): void
    {
        $this->authz->method('requirePermission');

        $response = $this->controller->list($this->query(['limit' => '10', 'cursor' => 'not-valid-base64!!!']));
        $body     = $this->decode($response);

        // Bad cursor is silently ignored → full result
        $this->assertCount(5, $body['data']);
    }

    private function query(array $extra = []): Request
    {
        $params = array_merge(['flagName' => 'checkout', 'projectId' => 'default', 'environment' => 'production'], $extra);

        return Request::create('/api/management/v1/change-requests', 'GET', $params);
    }

    private function decode(object $response): array
    {
        return json_decode($response->getContent(), true);
    }

    private function clockAt(\DateTimeImmutable $at): ClockInterface
    {
        return new class($at) implements ClockInterface {
            public function __construct(private \DateTimeImmutable $at) {}
            public function now(): \DateTimeImmutable { return $this->at; }
        };
    }
}
