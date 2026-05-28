<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Failover;

enum CircuitBreakerState
{
    case Closed;   // normal — all requests pass through
    case Open;     // tripped — requests blocked until reset timeout elapses
    case HalfOpen; // probing — one request allowed to test recovery
}
