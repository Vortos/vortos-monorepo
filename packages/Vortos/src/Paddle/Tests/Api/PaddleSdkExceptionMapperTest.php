<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Api;

use Paddle\SDK\Exceptions\ApiError;
use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\PaddleSdkExceptionMapper;
use Vortos\Paddle\Exception\PaddleAuthException;
use Vortos\Paddle\Exception\PaddleConflictException;
use Vortos\Paddle\Exception\PaddleNotFoundException;
use Vortos\Paddle\Exception\PaddleRateLimitException;
use Vortos\Paddle\Exception\PaddleValidationException;
use Vortos\Paddle\Exception\PaddleApiException;

final class PaddleSdkExceptionMapperTest extends TestCase
{
    private PaddleSdkExceptionMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new PaddleSdkExceptionMapper();
    }

    private function makeApiError(string $type, string $errorCode = 'some_error', ?int $retryAfter = null): ApiError
    {
        $error = ApiError::fromErrorData([
            'type'              => $type,
            'code'              => $errorCode,
            'detail'            => 'Error detail',
            'documentation_url' => 'https://paddle.com/errors',
        ], $retryAfter);

        return $error;
    }

    public function test_authentication_error_maps_to_auth_exception(): void
    {
        $error  = $this->makeApiError('authentication');
        $mapped = $this->mapper->map($error);
        $this->assertInstanceOf(PaddleAuthException::class, $mapped);
    }

    public function test_forbidden_error_maps_to_auth_exception(): void
    {
        $error  = $this->makeApiError('forbidden');
        $mapped = $this->mapper->map($error);
        $this->assertInstanceOf(PaddleAuthException::class, $mapped);
    }

    public function test_not_found_error_maps_to_not_found_exception(): void
    {
        $error  = $this->makeApiError('not_found');
        $mapped = $this->mapper->map($error);
        $this->assertInstanceOf(PaddleNotFoundException::class, $mapped);
    }

    public function test_conflict_error_maps_to_conflict_exception(): void
    {
        $error  = $this->makeApiError('conflict');
        $mapped = $this->mapper->map($error);
        $this->assertInstanceOf(PaddleConflictException::class, $mapped);
    }

    public function test_request_error_maps_to_validation_exception(): void
    {
        $error  = $this->makeApiError('request');
        $mapped = $this->mapper->map($error);
        $this->assertInstanceOf(PaddleValidationException::class, $mapped);
    }

    public function test_unprocessable_entity_maps_to_validation_exception(): void
    {
        $error  = $this->makeApiError('unprocessable_entity');
        $mapped = $this->mapper->map($error);
        $this->assertInstanceOf(PaddleValidationException::class, $mapped);
    }

    public function test_rate_limit_via_retry_after_maps_to_rate_limit_exception(): void
    {
        $error  = $this->makeApiError('too_many_requests', 'rate_limit', retryAfter: 30);
        $mapped = $this->mapper->map($error);
        $this->assertInstanceOf(PaddleRateLimitException::class, $mapped);
        $this->assertSame(30, $mapped->retryAfterSeconds);
    }

    public function test_unknown_error_type_maps_to_api_exception(): void
    {
        $error  = $this->makeApiError('internal_server_error', 'server_error');
        $mapped = $this->mapper->map($error);
        $this->assertInstanceOf(PaddleApiException::class, $mapped);
    }

    public function test_mapped_exception_preserves_original_as_previous(): void
    {
        $error  = $this->makeApiError('not_found');
        $mapped = $this->mapper->map($error);
        $this->assertSame($error, $mapped->getPrevious());
    }

    public function test_mapped_exception_has_error_code(): void
    {
        $error  = $this->makeApiError('not_found', 'subscription_not_found');
        $mapped = $this->mapper->map($error);
        $this->assertSame('subscription_not_found', $mapped->errorCode);
    }

    public function test_is_application_exception_always_true_for_api_errors(): void
    {
        $this->assertTrue($this->mapper->isApplicationException($this->makeApiError('not_found')));
        $this->assertTrue($this->mapper->isApplicationException($this->makeApiError('authentication')));
        $this->assertTrue($this->mapper->isApplicationException($this->makeApiError('internal_server_error')));
    }
}
