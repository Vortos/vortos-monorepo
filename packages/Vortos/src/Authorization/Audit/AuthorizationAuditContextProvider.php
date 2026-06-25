<?php

declare(strict_types=1);

namespace Vortos\Authorization\Audit;

use Symfony\Component\HttpFoundation\RequestStack;
use Vortos\Http\Contract\IpResolverInterface;

final class AuthorizationAuditContextProvider
{
    public function __construct(
        private readonly ?RequestStack $requestStack = null,
        private readonly ?object $tracer = null,
        private readonly IpResolverInterface $ipResolver = new \Vortos\Http\IpResolver\RemoteAddrIpResolver(),
    ) {
    }

    public function current(): AuthorizationAuditContext
    {
        $request = $this->requestStack?->getCurrentRequest();
        $correlationId = null;

        if ($this->tracer !== null && method_exists($this->tracer, 'currentCorrelationId')) {
            $correlationId = $this->tracer->currentCorrelationId();
        }

        $ipAddress = $request !== null
            ? $this->ipResolver->resolve($request)
            : null;

        return new AuthorizationAuditContext(
            requestId: $request?->headers->get('X-Request-ID'),
            correlationId: is_string($correlationId) ? $correlationId : $request?->headers->get('X-Correlation-ID'),
            ipAddress: $ipAddress,
            userAgent: $request?->headers->get('User-Agent'),
            httpMethod: $request?->getMethod(),
            path: $request?->getPathInfo(),
            route: is_string($request?->attributes->get('_route')) ? $request->attributes->get('_route') : null,
        );
    }
}
