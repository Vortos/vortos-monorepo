<?php

declare(strict_types=1);

namespace Vortos\Http\Contract;

use Symfony\Component\HttpFoundation\Response;
use Vortos\Http\Request;

interface ExceptionHandlerInterface
{
    /**
     * Handle a throwable and return a Response, or null to pass to the next handler.
     */
    public function handle(\Throwable $e, Request $request): ?Response;
}
