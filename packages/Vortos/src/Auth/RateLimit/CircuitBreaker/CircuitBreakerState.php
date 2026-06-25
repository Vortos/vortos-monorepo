<?php
declare(strict_types=1);

namespace Vortos\Auth\RateLimit\CircuitBreaker;

enum CircuitBreakerState
{
    case Closed;
    case Open;
    case HalfOpen;
}
