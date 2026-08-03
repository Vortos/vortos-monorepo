<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Api;

use Paddle\SDK\Exceptions\ApiError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\PaddleSdkExceptionMapper;
use Vortos\Paddle\Exception\PaddleApiException;
use Vortos\Paddle\Exception\PaddleAuthException;
use Vortos\Paddle\Exception\PaddleConflictException;
use Vortos\Paddle\Exception\PaddleNotFoundException;
use Vortos\Paddle\Exception\PaddleRateLimitException;
use Vortos\Paddle\Exception\PaddleValidationException;

/**
 * Every error here is built from a body shaped like one Paddle actually returns —
 * `type` is `request_error` or `api_error` and nothing else, because those are the
 * only two values the API has.
 *
 * That constraint is the point of this file. Its predecessor invented types
 * ('conflict', 'not_found', 'authentication') that no Paddle response has ever
 * carried, so the tests and the mapper agreed with each other while both disagreed
 * with the API, and a 409 on customer creation reached production as a 500.
 */
final class PaddleSdkExceptionMapperTest extends TestCase
{
    private PaddleSdkExceptionMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new PaddleSdkExceptionMapper();
    }

    /** A 4xx, exactly as Paddle sends one. */
    private function requestError(string $code, string $detail = 'Error detail', ?int $retryAfter = null): ApiError
    {
        return ApiError::fromErrorData([
            'type'              => 'request_error',
            'code'              => $code,
            'detail'            => $detail,
            'documentation_url' => 'https://developer.paddle.com/errors/' . $code,
        ], $retryAfter);
    }

    /** A 5xx, exactly as Paddle sends one. */
    private function apiError(string $code, string $detail = 'Error detail'): ApiError
    {
        return ApiError::fromErrorData([
            'type'              => 'api_error',
            'code'              => $code,
            'detail'            => $detail,
            'documentation_url' => 'https://developer.paddle.com/errors/' . $code,
        ], null);
    }

    // ── The regression this file exists for ──────────────────────────────────

    /**
     * The verbatim body Paddle sandbox answers `POST /customers` with when the email
     * already belongs to a customer, captured 2026-08-03. Note `request_error`: the
     * only signal that this is a 409 rather than a 400 is the code.
     */
    public function test_customer_already_exists_maps_to_conflict_carrying_the_customer_id(): void
    {
        $error = ApiError::fromErrorData([
            'type'              => 'request_error',
            'code'              => 'customer_already_exists',
            'detail'            => 'customer email conflicts with customer of id ctm_01jnrtysqtbd9a54f13518fap1',
            'documentation_url' => 'https://developer.paddle.com/v1/errors/customers/customer_already_exists',
        ], null);

        $mapped = $this->mapper->map($error);

        $this->assertInstanceOf(PaddleConflictException::class, $mapped);
        $this->assertSame('ctm_01jnrtysqtbd9a54f13518fap1', $mapped->conflictingEntityId);
        $this->assertSame('ctm_01jnrtysqtbd9a54f13518fap1', $mapped->conflictingEntityIdOfType('ctm_'));
    }

    // ── Classification ───────────────────────────────────────────────────────

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function sharedCodeProvider(): iterable
    {
        yield 'authentication_malformed'   => ['authentication_malformed', PaddleAuthException::class];
        yield 'authentication_missing'     => ['authentication_missing', PaddleAuthException::class];
        yield 'invalid_token'              => ['invalid_token', PaddleAuthException::class];
        yield 'invalid_client_token'       => ['invalid_client_token', PaddleAuthException::class];
        yield 'forbidden'                  => ['forbidden', PaddleAuthException::class];
        yield 'paddle_billing_not_enabled' => ['paddle_billing_not_enabled', PaddleAuthException::class];

        yield 'not_found'                  => ['not_found', PaddleNotFoundException::class];

        yield 'conflict'                   => ['conflict', PaddleConflictException::class];
        yield 'concurrent_modification'    => ['concurrent_modification', PaddleConflictException::class];

        yield 'bad_request'                => ['bad_request', PaddleValidationException::class];
        yield 'invalid_field'              => ['invalid_field', PaddleValidationException::class];
        yield 'invalid_json'               => ['invalid_json', PaddleValidationException::class];
        yield 'invalid_url'                => ['invalid_url', PaddleValidationException::class];
        yield 'method_not_allowed'         => ['method_not_allowed', PaddleValidationException::class];
        yield 'request_body_too_large'     => ['request_body_too_large', PaddleValidationException::class];
        yield 'unsupported_media_type'     => ['unsupported_media_type', PaddleValidationException::class];
    }

    /**
     * @param class-string $expected
     */
    #[DataProvider('sharedCodeProvider')]
    public function test_shared_codes_classify_by_code_not_by_type(string $code, string $expected): void
    {
        $mapped = $this->mapper->map($this->requestError($code));

        $this->assertInstanceOf($expected, $mapped);
    }

    public function test_per_entity_not_found_codes_map_to_not_found(): void
    {
        foreach (['subscription_not_found', 'transaction_not_found', 'price_not_found'] as $code) {
            $this->assertInstanceOf(
                PaddleNotFoundException::class,
                $this->mapper->map($this->requestError($code)),
                $code,
            );
        }
    }

    public function test_per_entity_already_exists_codes_map_to_conflict(): void
    {
        foreach (['customer_already_exists', 'business_already_exists'] as $code) {
            $this->assertInstanceOf(
                PaddleConflictException::class,
                $this->mapper->map($this->requestError($code)),
                $code,
            );
        }
    }

    // ── Rate limiting ────────────────────────────────────────────────────────

    public function test_retry_after_header_maps_to_rate_limit_with_its_delay(): void
    {
        $mapped = $this->mapper->map($this->requestError('too_many_requests', retryAfter: 30));

        $this->assertInstanceOf(PaddleRateLimitException::class, $mapped);
        $this->assertSame(30, $mapped->retryAfterSeconds);
    }

    public function test_too_many_requests_without_a_header_is_still_a_rate_limit(): void
    {
        $mapped = $this->mapper->map($this->requestError('too_many_requests'));

        $this->assertInstanceOf(PaddleRateLimitException::class, $mapped);
        $this->assertSame(0, $mapped->retryAfterSeconds);
    }

    // ── Fail-closed on anything unrecognised ─────────────────────────────────

    public function test_server_side_codes_stay_unclassified(): void
    {
        foreach (['internal_error', 'bad_gateway', 'service_unavailable', 'temporarily_unavailable'] as $code) {
            $mapped = $this->mapper->map($this->apiError($code));

            $this->assertInstanceOf(PaddleApiException::class, $mapped, $code);
            $this->assertNotInstanceOf(PaddleConflictException::class, $mapped, $code);
            $this->assertNotInstanceOf(PaddleNotFoundException::class, $mapped, $code);
            $this->assertNotInstanceOf(PaddleAuthException::class, $mapped, $code);
        }
    }

    /**
     * A code added to Paddle's catalogue after this mapper was written must not be
     * guessed into a class callers branch on. The base class is the honest answer.
     */
    public function test_an_unknown_code_is_not_promoted_to_a_subclass(): void
    {
        $mapped = $this->mapper->map($this->requestError('some_code_invented_after_this_test'));

        $this->assertSame(PaddleApiException::class, $mapped::class);
    }

    // ── What the mapped exception carries ────────────────────────────────────

    public function test_mapped_exception_preserves_code_type_detail_and_cause(): void
    {
        $error  = $this->requestError('subscription_not_found', 'subscription does not exist');
        $mapped = $this->mapper->map($error);

        $this->assertSame('subscription_not_found', $mapped->errorCode);
        $this->assertSame('request_error', $mapped->errorType);
        $this->assertSame('subscription does not exist', $mapped->getMessage());
        $this->assertSame($error, $mapped->getPrevious());
    }

    public function test_conflict_without_an_id_in_the_detail_carries_none(): void
    {
        $mapped = $this->mapper->map($this->requestError('conflict', 'nothing quotable here'));

        $this->assertInstanceOf(PaddleConflictException::class, $mapped);
        $this->assertNull($mapped->conflictingEntityId);
    }

    public function test_is_application_exception_always_true_for_api_errors(): void
    {
        $this->assertTrue($this->mapper->isApplicationException($this->requestError('not_found')));
        $this->assertTrue($this->mapper->isApplicationException($this->apiError('internal_error')));
    }
}
