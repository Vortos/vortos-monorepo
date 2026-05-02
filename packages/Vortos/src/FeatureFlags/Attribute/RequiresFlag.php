<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class RequiresFlag
{
    public function __construct(public readonly string $flag) {}
}
