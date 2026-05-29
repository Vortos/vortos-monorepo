<?php

declare(strict_types=1);

namespace Vortos\Paddle\Exception;

final class PaddleCircuitOpenException extends PaddleException
{
    public static function create(): self
    {
        return new self('Paddle API circuit breaker is open.');
    }
}
