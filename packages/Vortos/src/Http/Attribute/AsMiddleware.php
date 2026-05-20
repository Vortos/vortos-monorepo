<?php

declare(strict_types=1);

namespace Vortos\Http\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class AsMiddleware
{
    public function __construct(public readonly int $order) {}
}
