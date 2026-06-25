<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Middleware;

use Symfony\Component\HttpFoundation\Response;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\SdkKey\SdkKeyService;
use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\IpResolverInterface;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\JsonResponse;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;

#[AsMiddleware(order: MiddlewareOrder::AUTH)]
final class SdkKeyAuthMiddleware implements MiddlewareInterface
{
    /** Routes that require a valid SDK key (eval plane). */
    private const SDK_KEY_ROUTES = [
        '/api/flags',
        '/api/flags/exposures',
    ];

    public function __construct(
        private readonly SdkKeyService $sdkKeyService,
        private readonly ProjectContext $projectContext,
        private readonly FlagScopeContext $scopeContext,
        private readonly IpResolverInterface $ipResolver = new \Vortos\Http\IpResolver\RemoteAddrIpResolver(),
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if (!$this->requiresSdkKeyAuth($request)) {
            return $next($request);
        }

        $header = $request->headers->get('Authorization', '');

        if (!str_starts_with((string) $header, 'Bearer ')) {
            return new JsonResponse(['error' => 'SDK key required'], 401);
        }

        $rawKey     = substr((string) $header, 7);
        $projectId  = $request->headers->get('X-Vortos-Project', ProjectContext::DEFAULT_PROJECT);
        $environment = $request->headers->get('X-Vortos-Environment', FlagScopeContext::ENV_PRODUCTION);
        $remoteIp   = $this->ipResolver->resolve($request);

        $sdkKey = $this->sdkKeyService->validate($rawKey, $projectId, $environment, $remoteIp);

        if ($sdkKey === null) {
            return new JsonResponse(['error' => 'Invalid or revoked SDK key'], 401);
        }

        // Set project + env from the validated key (server-derived, not client input).
        $this->projectContext->withProject($sdkKey->projectId);
        $this->scopeContext->withEnvironment($sdkKey->environment);
        $request->attributes->set('_sdk_key_id', $sdkKey->id);

        return $next($request);
    }

    private function requiresSdkKeyAuth(Request $request): bool
    {
        $path = rtrim($request->getPathInfo(), '/');

        foreach (self::SDK_KEY_ROUTES as $route) {
            if ($path === $route || str_starts_with($path, $route . '/')) {
                return true;
            }
        }

        return false;
    }
}
