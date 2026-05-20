<?php

declare(strict_types=1);

namespace Vortos\Http\Controller\Resolver;

use Vortos\Http\Contract\ArgumentValueResolverInterface;
use Vortos\Http\Request;

final class VariadicValueResolver implements ArgumentValueResolverInterface
{
    public function supports(Request $request, \ReflectionParameter $param): bool
    {
        return $param->isVariadic();
    }

    public function resolve(Request $request, \ReflectionParameter $param): mixed
    {
        $value = $request->attributes->get($param->getName(), []);
        return is_array($value) ? $value : [$value];
    }
}
