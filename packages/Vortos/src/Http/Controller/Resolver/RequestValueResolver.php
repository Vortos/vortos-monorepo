<?php

declare(strict_types=1);

namespace Vortos\Http\Controller\Resolver;

use Vortos\Http\Contract\ArgumentValueResolverInterface;
use Vortos\Http\Request;

final class RequestValueResolver implements ArgumentValueResolverInterface
{
    public function supports(Request $request, \ReflectionParameter $param): bool
    {
        $type = $param->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return false;
        }
        return is_a($type->getName(), Request::class, true)
            || is_a($type->getName(), \Symfony\Component\HttpFoundation\Request::class, true);
    }

    public function resolve(Request $request, \ReflectionParameter $param): mixed
    {
        return $request;
    }
}
