<?php

declare(strict_types=1);

namespace Vortos\Http\Contract;

use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface TerminableMiddlewareInterface
{
    public function terminate(Request $request, Response $response): void;
}
