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
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Guardrail\GuardrailPolicy;
use Vortos\FeatureFlags\Guardrail\GuardrailPolicyService;
use Vortos\FeatureFlags\Guardrail\Storage\GuardrailPolicyStorageInterface;
use Vortos\FeatureFlags\Http\Management\GuardrailController;
use Vortos\FeatureFlags\Http\Management\ManagementResponseFactory;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Messaging\Contract\EventBusInterface;

final class GuardrailControllerTest extends TestCase
{
    private ManagementAuthzGateInterface $authz;
    private GuardrailController $controller;
    private GuardrailPolicyService $service;

    protected function setUp(): void
    {
        $this->authz = $this->createMock(ManagementAuthzGateInterface::class);
        $rateLimit   = $this->createMock(FlagRateLimitService::class);

        $this->service = new GuardrailPolicyService($this->inMemoryStorage(), $this->createMock(EventBusInterface::class), $this->clockAt('2026-06-22T10:00:00+00:00'));

        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('test-user', ['ROLE_ADMIN']));
        $currentUser = new CurrentUserProvider($adapter);

        $validator = new VortosValidator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());

        $this->controller = new GuardrailController(
            service: $this->service, authz: $this->authz, rateLimit: $rateLimit,
            response: new ManagementResponseFactory(), currentUser: $currentUser, validator: $validator,
        );
    }

    public function test_list_requires_flags_admin_any(): void
    {
        $this->authz->method('requirePermission')->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->list($this->query(['flagName' => 'checkout', 'environment' => 'production']));
    }

    public function test_list_returns_items(): void
    {
        $this->authz->method('requirePermission');
        $this->seed();

        $response = $this->controller->list($this->query(['flagName' => 'checkout', 'projectId' => 'default', 'environment' => 'production']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->decode($response)['data']);
    }

    public function test_create_requires_flags_admin_any(): void
    {
        $this->authz->method('requirePermission')->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->create($this->json($this->validBody()));
    }

    public function test_create_returns_201(): void
    {
        $this->authz->method('requirePermission');

        $response = $this->controller->create($this->json($this->validBody()));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('watching', $this->decode($response)['data']['status']);
    }

    public function test_create_rejects_empty_conditions(): void
    {
        $this->authz->method('requirePermission');

        $body = $this->validBody();
        $body['conditions'] = [];

        $this->expectException(ValidationException::class);
        $this->controller->create($this->json($body));
    }

    public function test_update_returns_200(): void
    {
        $this->authz->method('requirePermission');
        $policy = $this->seed();

        $response = $this->controller->update($policy->id, $this->json(['cooldownSeconds' => 1200]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1200, $this->decode($response)['data']['cooldown_seconds']);
    }

    public function test_update_404_for_missing(): void
    {
        $this->authz->method('requirePermission');

        $this->expectException(NotFoundException::class);
        $this->controller->update('ghost', $this->json(['cooldownSeconds' => 1200]));
    }

    public function test_delete_returns_204(): void
    {
        $this->authz->method('requirePermission');
        $policy = $this->seed();

        $response = $this->controller->delete($policy->id);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNull($this->service->findById($policy->id));
    }

    public function test_delete_404_for_missing(): void
    {
        $this->authz->method('requirePermission');

        $this->expectException(NotFoundException::class);
        $this->controller->delete('ghost');
    }

    private function validBody(): array
    {
        return [
            'flagName'    => 'checkout',
            'projectId'   => 'default',
            'environment' => 'production',
            'action'      => 'disable',
            'conditions'  => [
                ['metric_kind' => 'error_rate', 'threshold' => 0.05, 'comparison_operator' => 'gt'],
            ],
            'consecutiveWindows' => 2,
            'windowSeconds'      => 300,
            'cooldownSeconds'    => 600,
        ];
    }

    private function seed(): GuardrailPolicy
    {
        return $this->service->create(
            'checkout', 'default', 'production', \Vortos\FeatureFlags\Guardrail\GuardrailAction::Disable,
            [['metric_kind' => 'error_rate', 'threshold' => 0.05, 'comparison_operator' => 'gt']],
            2, 300, 600, 'admin',
        );
    }

    private function json(array $data): Request
    {
        return Request::create('/api/management/v1/guardrails', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($data));
    }

    private function query(array $query): Request
    {
        return Request::create('/api/management/v1/guardrails', 'GET', $query);
    }

    private function decode(object $response): array
    {
        return json_decode($response->getContent(), true);
    }

    private function clockAt(string $iso): ClockInterface
    {
        return new class($iso) implements ClockInterface {
            public function __construct(private string $iso) {}
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable($this->iso); }
        };
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
