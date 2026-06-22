<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Exception;

use Vortos\Http\Exception\HttpException;

final class TooManyRequestsException extends HttpException
{
    public function __construct(int $retryAfter = 60)
    {
        parent::__construct(429, 'Too Many Requests', ['Retry-After' => (string) $retryAfter]);
    }
}
