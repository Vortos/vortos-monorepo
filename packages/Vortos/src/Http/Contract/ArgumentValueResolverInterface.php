<?php

declare(strict_types=1);

namespace Vortos\Http\Contract;

use Vortos\Http\Request;

interface ArgumentValueResolverInterface
{
    public function supports(Request $request, \ReflectionParameter $param): bool;

    public function resolve(Request $request, \ReflectionParameter $param): mixed;
}
