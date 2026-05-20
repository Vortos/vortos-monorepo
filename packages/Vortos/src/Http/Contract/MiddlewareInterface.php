<?php

declare(strict_types=1);

namespace Vortos\Http\Contract;

use Symfony\Component\HttpFoundation\Response;
use Vortos\Http\Request;

interface MiddlewareInterface
{
    public function handle(Request $request, \Closure $next): Response;
}
