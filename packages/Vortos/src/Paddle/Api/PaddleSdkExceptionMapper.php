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

final class PaddleSdkExceptionMapper
{
    public function map(ApiError $error): PaddleApiException
    {
        // Rate limit: retryAfter is set on 429 responses
        if ($error->retryAfter !== null) {
            return new PaddleRateLimitException(
                sprintf('Paddle rate limit exceeded. Retry after %d seconds.', $error->retryAfter),
                retryAfterSeconds: $error->retryAfter,
                previous: $error,
            );
        }

        return match ($error->type) {
            'authentication', 'forbidden' => new PaddleAuthException(
                $error->getMessage(),
                $error->errorCode,
                $error->type,
                $error,
            ),
            'not_found' => new PaddleNotFoundException(
                $error->getMessage(),
                $error->errorCode,
                $error->type,
                $error,
            ),
            'conflict' => new PaddleConflictException(
                $error->getMessage(),
                $error->errorCode,
                $error->type,
                $error,
            ),
            'request', 'unprocessable_entity' => new PaddleValidationException(
                $error->getMessage(),
                $error->errorCode,
                $error->type,
                $error,
            ),
            default => new PaddleApiException(
                $error->getMessage(),
                $error->errorCode,
                $error->type,
                $error,
            ),
        };
    }

    public function isApplicationException(ApiError $error): bool
    {
        // All ApiError responses mean the API is reachable — application-level errors only
        return true;
    }
}
