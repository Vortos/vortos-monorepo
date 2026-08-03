<?php

declare(strict_types=1);

namespace Vortos\Paddle\Api;

use Paddle\SDK\Exceptions\ApiError;
use Vortos\Paddle\Exception\PaddleApiException;
use Vortos\Paddle\Exception\PaddleAuthException;
use Vortos\Paddle\Exception\PaddleConflictException;
use Vortos\Paddle\Exception\PaddleNotFoundException;
use Vortos\Paddle\Exception\PaddleRateLimitException;
use Vortos\Paddle\Exception\PaddleValidationException;

/**
 * Turns a Paddle SDK error into the wrapper exception callers actually catch.
 *
 * Classified on the error **code**, never on the `type` field. Paddle's `type` only
 * ever holds `request_error` (every 4xx) or `api_error` (every 5xx) — it does not
 * distinguish a 404 from a 409, so a mapper keyed on it can only ever produce one
 * answer per family. An earlier version of this class matched `type` against
 * 'conflict', 'not_found', 'authentication' and friends: values Paddle has never
 * sent. Every arm was dead, every error became a bare {@see PaddleApiException}, and
 * `ImmediateCustomerService::findOrCreate()` — which catches only a conflict — turned
 * the ordinary "this email is already a customer" 409 into a 500.
 *
 * Fail-closed by construction. A code this class does not recognise maps to the base
 * {@see PaddleApiException}, never to a subclass: callers branch on these types to
 * decide whether to bill, retry, or let a failure through, and a guessed
 * classification is the one outcome worse than an unclassified one. Every subclass
 * extends PaddleApiException, so a caller catching the base still catches everything.
 *
 * @see https://developer.paddle.com/errors/overview
 */
final class PaddleSdkExceptionMapper
{
    /**
     * 401 and 403. The credential or the account is the problem, and no amount of
     * retrying or rewording the request will change the answer.
     */
    private const AUTH_CODES = [
        'authentication_malformed',
        'authentication_missing',
        'invalid_token',
        'invalid_client_token',
        'forbidden',
        'paddle_billing_not_enabled',
    ];

    /** 404 on the shared code. Per-entity codes are caught by the suffix below. */
    private const NOT_FOUND_CODES = [
        'not_found',
    ];

    /**
     * 409. The request was well formed and the entity it names already exists, or
     * moved underneath us. Both are states a caller can reconcile rather than fail on.
     */
    private const CONFLICT_CODES = [
        'conflict',
        'concurrent_modification',
    ];

    /** 4xx faults in what we sent: shape, encoding, size, route. */
    private const VALIDATION_CODES = [
        'bad_request',
        'invalid_field',
        'invalid_json',
        'invalid_time_query_parameter',
        'invalid_url',
        'method_not_allowed',
        'request_body_too_large',
        'request_headers_too_large',
        'unexpected_request_body',
        'unsupported_media_type',
    ];

    /** 429, for the responses that arrive without a Retry-After header. */
    private const RATE_LIMIT_CODES = [
        'too_many_requests',
    ];

    /**
     * Paddle names per-entity errors `<entity>_<condition>`, so the condition is a
     * reliable suffix: `customer_already_exists`, `subscription_not_found`. Matched
     * on the whole tail rather than a substring, so nothing else can collide.
     */
    private const NOT_FOUND_SUFFIX = '_not_found';
    private const CONFLICT_SUFFIX  = '_already_exists';

    public function map(ApiError $error): PaddleApiException
    {
        $code = $error->errorCode;

        // Retry-After is the authoritative signal — it carries the delay Paddle wants
        // — but a 429 without the header is still a 429.
        if ($error->retryAfter !== null || in_array($code, self::RATE_LIMIT_CODES, true)) {
            $retryAfter = $error->retryAfter ?? 0;

            return new PaddleRateLimitException(
                sprintf('Paddle rate limit exceeded. Retry after %d seconds.', $retryAfter),
                retryAfterSeconds: $retryAfter,
                previous: $error,
            );
        }

        if (in_array($code, self::AUTH_CODES, true)) {
            return new PaddleAuthException($error->getMessage(), $code, $error->type, $error);
        }

        if (in_array($code, self::NOT_FOUND_CODES, true) || str_ends_with($code, self::NOT_FOUND_SUFFIX)) {
            return new PaddleNotFoundException($error->getMessage(), $code, $error->type, $error);
        }

        if (in_array($code, self::CONFLICT_CODES, true) || str_ends_with($code, self::CONFLICT_SUFFIX)) {
            return new PaddleConflictException(
                $error->getMessage(),
                $code,
                $error->type,
                $error,
                PaddleConflictException::parseEntityId($error->getMessage()),
            );
        }

        if (in_array($code, self::VALIDATION_CODES, true)) {
            return new PaddleValidationException($error->getMessage(), $code, $error->type, $error);
        }

        // Everything left over — 5xx codes, and any code added to Paddle's catalogue
        // since this list was written. Unclassified on purpose; see the class docblock.
        return new PaddleApiException($error->getMessage(), $code, $error->type, $error);
    }

    public function isApplicationException(ApiError $error): bool
    {
        // All ApiError responses mean the API is reachable — application-level errors only
        return true;
    }
}
