<?php

declare(strict_types=1);

namespace Vortos\Http\Routing;

use Symfony\Component\Routing\Exception\MethodNotAllowedException as SymfonyMethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Vortos\Http\Exception\MethodNotAllowedException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;

final class Router
{
    public function __construct(
        private readonly UrlMatcherInterface $matcher,
        private readonly RequestContext $context,
    ) {}

    public function match(Request $request): void
    {
        $this->context->fromRequest($request);
        $this->matcher->setContext($this->context);

        try {
            $parameters = $this->matcher->match($request->getPathInfo());
        } catch (ResourceNotFoundException $e) {
            throw new NotFoundException('No route found for "' . $request->getMethod() . ' ' . $request->getPathInfo() . '"', $e);
        } catch (SymfonyMethodNotAllowedException $e) {
            throw new MethodNotAllowedException($e->getAllowedMethods(), 'Method "' . $request->getMethod() . '" not allowed.', $e);
        }

        $request->attributes->add($parameters);
    }
}
