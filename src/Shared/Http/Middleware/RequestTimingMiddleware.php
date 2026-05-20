<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Symfony\Component\HttpFoundation\Response;
use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;

#[AsMiddleware(order: MiddlewareOrder::INNERMOST + 1)]
final class RequestTimingMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next): Response
    {
        $startNs = hrtime(true);

        $response = $next($request);

        $elapsedMs = (hrtime(true) - $startNs) / 1_000_000;

        $response->headers->set('X-Response-Time', sprintf('%.2fms', $elapsedMs));

        return $response;
    }
}
