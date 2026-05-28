<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Attribute;

/**
 * Marks a class as an email middleware and sets its execution priority.
 *
 * Higher priority runs first. Vortos built-in middleware uses 500–1000.
 * Application middleware should use 1–499.
 *
 * Example:
 *   #[AsEmailMiddleware(priority: 300)]
 *   class MyMiddleware implements EmailMiddlewareInterface { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsEmailMiddleware
{
    public function __construct(public readonly int $priority = 0) {}
}
