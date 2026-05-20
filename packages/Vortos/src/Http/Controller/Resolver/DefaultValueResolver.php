<?php

declare(strict_types=1);

namespace Vortos\Http\Controller\Resolver;

use Vortos\Http\Contract\ArgumentValueResolverInterface;
use Vortos\Http\Request;

final class DefaultValueResolver implements ArgumentValueResolverInterface
{
    public function supports(Request $request, \ReflectionParameter $param): bool
    {
        return $param->isDefaultValueAvailable() || ($param->getType()?->allowsNull() ?? false);
    }

    public function resolve(Request $request, \ReflectionParameter $param): mixed
    {
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        return null;
    }
}
