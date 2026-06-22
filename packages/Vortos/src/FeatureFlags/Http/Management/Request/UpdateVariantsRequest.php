<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management\Request;

use Vortos\Http\Request\RequestDto;

final class UpdateVariantsRequest extends RequestDto
{
    public ?array $variants = null;
}
