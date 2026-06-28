<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Attribute;

/**
 * Marks a class as a decorator for an existing service.
 *
 * The decorated service is injected as the constructor argument whose type matches
 * $decorates. Multiple decorators on the same target chain by priority — higher
 * priority = outer wrapper.
 *
 * @param string $decorates  Interface FQCN, concrete class FQCN, or any service ID.
 * @param int    $priority   Higher = outer (wraps first). Default 0.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsDecorator
{
    public function __construct(
        public readonly string $decorates,
        public readonly int $priority = 0,
    ) {}
}
