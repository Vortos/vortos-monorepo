<?php

declare(strict_types=1);

namespace Vortos\Http\Contract;

use Symfony\Component\HttpFoundation\Request;

interface IpResolverInterface
{
    public function resolve(Request $request): string;
}
