<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http;

use Vortos\Http\Request;
use Vortos\FeatureFlags\FlagContext;

interface FlagContextResolverInterface
{
    public function resolve(Request $request): FlagContext;
}
