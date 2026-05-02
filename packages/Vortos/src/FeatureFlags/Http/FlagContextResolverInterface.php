<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http;

use Symfony\Component\HttpFoundation\Request;
use Vortos\FeatureFlags\FlagContext;

interface FlagContextResolverInterface
{
    public function resolve(Request $request): FlagContext;
}
