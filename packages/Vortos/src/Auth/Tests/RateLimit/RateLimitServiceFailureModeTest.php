<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\RateLimit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\RateLimit\Contract\RateLimitPolicyInterface;
use Vortos\Auth\RateLimit\Contract\RateLimitStoreInterface;
use Vortos\Auth\RateLimit\Exception\RateLimitStoreUnavailableException;
use Vortos\Auth\RateLimit\RateLimitFailureConfig;
use Vortos\Auth\RateLimit\RateLimitFailureMode;
use Vortos\Auth\RateLimit\RateLimitRule;
use Vortos\Auth\RateLimit\RateLimitScope;
use Vortos\Auth\RateLimit\RateLimitService;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Http\Request;

final class RateLimitServiceFailureModeTest extends TestCase
{
    private const CONTROLLER = 'App\\Controller\\LoginController';
    private const POLICY = 'App\\Policy\\LoginPolicy';

    public function test_fail_closed_returns_503_on_ip_scope(): void
    {
        $service = $this->buildService(
            storeThrows: true,
            failureConfig: new RateLimitFailureConfig(
                ipMode: RateLimitFailureMode::FailClosed,
            ),
        );

        $response = $service->enforce(
            $this->makeRequest(),
            RateLimitScope::Ip,
        );

        $this->assertNotNull($response);
        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $this->assertSame('30', $response->headers->get('Retry-After'));
    }

    public function test_fail_closed_returns_503_on_global_scope(): void
    {
        $service = $this->buildService(
            storeThrows: true,
            failureConfig: new RateLimitFailureConfig(
                globalMode: RateLimitFailureMode::FailClosed,
            ),
        );

        $response = $service->enforce(
            $this->makeRequest(),
            RateLimitScope::Global,
        );

        $this->assertNotNull($response);
        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
    }

    public function test_fail_open_allows_request_on_user_scope(): void
    {
        $service = $this->buildService(
            storeThrows: true,
            failureConfig: new RateLimitFailureConfig(
                userMode: RateLimitFailureMode::FailOpen,
            ),
        );

        $response = $service->enforce(
            $this->makeRequest(),
            RateLimitScope::User,
        );

        $this->assertNull($response);
    }

    public function test_fail_open_on_ip_allows_request_through(): void
    {
        $service = $this->buildService(
            storeThrows: true,
            failureConfig: new RateLimitFailureConfig(
                ipMode: RateLimitFailureMode::FailOpen,
            ),
        );

        $response = $service->enforce(
            $this->makeRequest(),
            RateLimitScope::Ip,
        );

        $this->assertNull($response);
    }

    public function test_normal_operation_unaffected(): void
    {
        $service = $this->buildService(
            storeThrows: false,
            storeReturnValue: 1,
        );

        $response = $service->enforce(
            $this->makeRequest(),
            RateLimitScope::Ip,
        );

        $this->assertNull($response);
    }

    public function test_normal_rate_limit_exceeded_still_returns_429(): void
    {
        $service = $this->buildService(
            storeThrows: false,
            storeReturnValue: 999,
        );

        $response = $service->enforce(
            $this->makeRequest(),
            RateLimitScope::Ip,
        );

        $this->assertNotNull($response);
        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    }

    public function test_503_response_is_problem_json(): void
    {
        $service = $this->buildService(
            storeThrows: true,
            failureConfig: new RateLimitFailureConfig(
                ipMode: RateLimitFailureMode::FailClosed,
            ),
        );

        $response = $service->enforce(
            $this->makeRequest(),
            RateLimitScope::Ip,
        );

        $this->assertNotNull($response);
        $body = json_decode($response->getContent(), true);
        $this->assertSame('Rate Limit Unavailable', $body['title']);
        $this->assertSame(503, $body['status']);
    }

    private function buildService(
        bool $storeThrows = false,
        int $storeReturnValue = 1,
        ?RateLimitFailureConfig $failureConfig = null,
    ): RateLimitService {
        $store = $this->createMock(RateLimitStoreInterface::class);

        if ($storeThrows) {
            $store->method('increment')
                ->willThrowException(new RateLimitStoreUnavailableException('Redis down'));
        } else {
            $store->method('increment')->willReturn($storeReturnValue);
            $store->method('getTtl')->willReturn(60);
        }

        $policy = new class implements RateLimitPolicyInterface {
            public function getLimit(UserIdentityInterface $identity): RateLimitRule
            {
                return new RateLimitRule(10, 60);
            }
        };

        $currentUser = new CurrentUserProvider(new ArrayAdapter());

        return new RateLimitService(
            currentUser: $currentUser,
            store: $store,
            routeMap: [
                self::CONTROLLER => [
                    ['policy' => self::POLICY, 'per' => RateLimitScope::Ip],
                    ['policy' => self::POLICY, 'per' => RateLimitScope::Global],
                    ['policy' => self::POLICY, 'per' => RateLimitScope::User],
                ],
            ],
            policies: [self::POLICY => $policy],
            failureConfig: $failureConfig ?? new RateLimitFailureConfig(),
        );
    }

    private function makeRequest(): Request
    {
        $request = Request::create('/login', 'POST');
        $request->attributes->set('_controller', self::CONTROLLER . '::login');
        return $request;
    }
}
