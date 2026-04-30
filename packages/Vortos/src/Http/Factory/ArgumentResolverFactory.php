<?php

declare(strict_types=1);

namespace Vortos\Http\Factory;

use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Vortos\Http\Request\RequestDtoArgumentResolver;

final class ArgumentResolverFactory
{
    public static function create(RequestDtoArgumentResolver $dtoResolver): ArgumentResolver
    {
        // 1. Get Symfony's default resolvers (Request attribute, Backed Enums, etc.)
        $resolvers = ArgumentResolver::getDefaultArgumentValueResolvers();

        // 2. Put your custom DTO resolver at the very beginning (acts like priority: 110)
        array_unshift($resolvers, $dtoResolver);

        // 3. Return the fully configured ArgumentResolver
        return new ArgumentResolver(null, $resolvers);
    }
}
