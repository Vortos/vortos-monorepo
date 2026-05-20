<?php

declare(strict_types=1);

namespace Vortos\Http\Controller\Resolver;

use Vortos\Http\Contract\ArgumentValueResolverInterface;
use Vortos\Http\Request;

final class RequestAttributeValueResolver implements ArgumentValueResolverInterface
{
    public function supports(Request $request, \ReflectionParameter $param): bool
    {
        return $request->attributes->has($param->getName());
    }

    public function resolve(Request $request, \ReflectionParameter $param): mixed
    {
        $value = $request->attributes->get($param->getName());
        $type  = $param->getType();

        if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin()) {
            return $value;
        }

        return match ($type->getName()) {
            'int'   => (int) $value,
            'float' => (float) $value,
            'bool'  => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }
}
