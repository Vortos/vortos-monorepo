<?php

declare(strict_types=1);

namespace Vortos\Paddle\Failover;

enum PaddleCircuitBreakerState
{
    case Closed;   // normal — all requests pass through
    case Open;     // tripped — requests blocked until reset timeout elapses
    case HalfOpen; // probing — one request allowed to test recovery
}
