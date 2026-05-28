<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsObjectStoreMiddleware
{
    public function __construct(public readonly int $priority = 0)
    {
    }
}
