<?php

declare(strict_types=1);

namespace Vortos\Security\Cors\Controller;

use Symfony\Component\HttpFoundation\Response;

/**
 * Safety-net for compile-time-injected OPTIONS routes.
 *
 * CorsPreflightCompilerPass registers an OPTIONS sibling route for every
 * non-OPTIONS route in the collection, pointing here. CorsMiddleware (order 95)
 * always short-circuits and returns the preflight response before this
 * controller is ever invoked.
 */
final class CorsPreflightController
{
    public function __invoke(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
