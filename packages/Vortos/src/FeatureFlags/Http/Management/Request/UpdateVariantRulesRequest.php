<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management\Request;

use Vortos\Http\Request\RequestDto;

final class UpdateVariantRulesRequest extends RequestDto
{
    /** @var array<string,array<int,array<string,mixed>>>|null variantName => FlagRule[] (null clears) */
    public ?array $variantRules = null;
}
