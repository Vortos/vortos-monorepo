<?php

declare(strict_types=1);

namespace Vortos\Http\Controller\Resolver;

use Vortos\Http\Contract\ArgumentValueResolverInterface;
use Vortos\Http\Request;
use Vortos\Http\Request\RequestDto;
use Vortos\Cqrs\Validation\VortosValidator;

final class RequestDtoValueResolver implements ArgumentValueResolverInterface
{
    public function __construct(private readonly VortosValidator $validator) {}

    public function supports(Request $request, \ReflectionParameter $param): bool
    {
        $type = $param->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return false;
        }
        return class_exists($type->getName()) && is_subclass_of($type->getName(), RequestDto::class);
    }

    public function resolve(Request $request, \ReflectionParameter $param): mixed
    {
        $type = $param->getType();
        assert($type instanceof \ReflectionNamedType);
        $class = $type->getName();
        return $class::fromRequest($request, $this->validator);
    }
}
