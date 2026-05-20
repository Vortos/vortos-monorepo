<?php

declare(strict_types=1);

namespace Vortos\Http\Controller\Resolver;

use Vortos\Http\Contract\ArgumentValueResolverInterface;
use Vortos\Http\Request;

final class BackedEnumValueResolver implements ArgumentValueResolverInterface
{
    public function supports(Request $request, \ReflectionParameter $param): bool
    {
        $type = $param->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return false;
        }
        $name = $type->getName();
        return enum_exists($name)
            && (new \ReflectionEnum($name))->isBacked()
            && $request->attributes->has($param->getName());
    }

    public function resolve(Request $request, \ReflectionParameter $param): mixed
    {
        $type = $param->getType();
        assert($type instanceof \ReflectionNamedType);
        $enumClass = $type->getName();
        $raw = $request->attributes->get($param->getName());

        $value = $enumClass::tryFrom($raw);

        if ($value === null) {
            throw new \ValueError(sprintf('"%s" is not a valid value for enum %s.', $raw, $enumClass));
        }

        return $value;
    }
}
