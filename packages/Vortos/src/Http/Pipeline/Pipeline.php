<?php

declare(strict_types=1);

namespace Vortos\Http\Pipeline;

use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class Pipeline
{
    private \Closure $chain;

    /**
     * @param MiddlewareInterface[] $middleware Ordered outermost-first (highest order → first to run)
     */
    public function __construct(array $middleware)
    {
        $this->chain = $this->buildChain($middleware);
    }

    public function run(Request $request, \Closure $core): SymfonyResponse
    {
        return ($this->chain)($request, $core);
    }

    /** @param MiddlewareInterface[] $middleware */
    private function buildChain(array $middleware): \Closure
    {
        // Build inside-out: innermost middleware wraps the core, outermost wraps everything.
        // We reverse so the first item in $middleware is the outermost layer.
        $chain = static fn(Request $request, \Closure $core): SymfonyResponse => $core($request);

        foreach (array_reverse($middleware) as $layer) {
            $next = $chain;
            $chain = static fn(Request $request, \Closure $core): SymfonyResponse =>
                $layer->handle($request, static fn(Request $req): SymfonyResponse => $next($req, $core));
        }

        return $chain;
    }
}
