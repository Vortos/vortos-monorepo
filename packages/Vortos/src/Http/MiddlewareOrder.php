<?php

declare(strict_types=1);

namespace Vortos\Http;

final class MiddlewareOrder
{
    public const OUTERMOST      = 1000; // TracingMiddleware, SecurityHeaders, HttpMetricsListener
    public const SECURITY       = 900;  // CORS, IP filter, request signature
    public const CSRF           = 800;
    public const RATE_LIMIT_IP  = 750;  // IP + Global rate limits — before auth (protects unauthed endpoints)
    public const AUTH           = 700;  // JWT, API key
    public const TWO_FACTOR     = 650;
    public const RATE_LIMIT_USER = 625; // User rate limits — after auth, before authorization
    public const AUTHORIZATION  = 600;
    public const OWNERSHIP      = 550;
    public const FEATURE_FLAGS  = 520;
    public const FEATURE_ACCESS = 500;
    public const QUOTA          = 200;  // Innermost business rule — after feature access
    public const INNERMOST      = 0;
}
