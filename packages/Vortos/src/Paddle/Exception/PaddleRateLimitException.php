<?php

declare(strict_types=1);

namespace Vortos\Paddle\Exception;

final class PaddleRateLimitException extends PaddleApiException
{
    public function __construct(
        string     $message,
        public readonly int $retryAfterSeconds = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'too_many_requests', 'too_many_requests', $previous);
    }
}
