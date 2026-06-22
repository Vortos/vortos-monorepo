<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Http\Middleware\SdkKeyAuthMiddleware;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\SdkKey\SdkKey;
use Vortos\FeatureFlags\SdkKey\SdkKeyService;
use Vortos\Http\Request;

final class SdkKeyAuthMiddlewareTest extends TestCase
{
    private SdkKeyService $sdkKeyService;
    private ProjectContext $projectContext;
    private FlagScopeContext $scopeContext;
    private SdkKeyAuthMiddleware $middleware;

    protected function setUp(): void
    {
        $this->sdkKeyService   = $this->createMock(SdkKeyService::class);
        $this->projectContext  = new ProjectContext();
        $this->scopeContext    = new FlagScopeContext();
        $this->middleware      = new SdkKeyAuthMiddleware(
            $this->sdkKeyService,
            $this->projectContext,
            $this->scopeContext,
        );
    }

    public function test_valid_key_passes_through_and_sets_context(): void
    {
        $sdkKey = $this->buildSdkKey('my-project', 'staging');
        $this->sdkKeyService->method('validate')->willReturn($sdkKey);

        $request = $this->flagRequest('Bearer vff_srv_validkey12345678901234');
        $next    = fn(Request $r): Response => new Response('ok', 200);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('my-project', $this->projectContext->projectId());
        $this->assertSame('staging', $this->scopeContext->environment());
        $this->assertSame($sdkKey->id, $request->attributes->get('_sdk_key_id'));
    }

    public function test_missing_authorization_header_returns_401(): void
    {
        $request = $this->flagRequest(null);
        $next    = fn(Request $r): Response => new Response('ok', 200);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_invalid_key_returns_401(): void
    {
        $this->sdkKeyService->method('validate')->willReturn(null);

        $request  = $this->flagRequest('Bearer vff_srv_invalidkey12345678901');
        $next     = fn(Request $r): Response => new Response('ok', 200);
        $response = $this->middleware->handle($request, $next);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_revoked_key_returns_401(): void
    {
        $this->sdkKeyService->method('validate')->willReturn(null);

        $request  = $this->flagRequest('Bearer vff_srv_revokedkey1234567890123');
        $next     = fn(Request $r): Response => new Response('ok', 200);
        $response = $this->middleware->handle($request, $next);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_non_sdk_route_passes_through_without_auth(): void
    {
        $request = Request::create('/api/management/v1/flags', 'GET');
        $next    = fn(Request $r): Response => new Response('ok', 200);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_exposures_route_requires_sdk_key(): void
    {
        $this->sdkKeyService->method('validate')->willReturn(null);

        $request  = Request::create('/api/flags/exposures', 'POST');
        $request->headers->set('Authorization', 'Bearer vff_srv_badkey');
        $next     = fn(Request $r): Response => new Response('ok', 200);
        $response = $this->middleware->handle($request, $next);

        $this->assertSame(401, $response->getStatusCode());
    }

    private function flagRequest(?string $authHeader): Request
    {
        $request = Request::create('/api/flags', 'GET');
        $request->headers->set('X-Vortos-Project', 'my-project');
        $request->headers->set('X-Vortos-Environment', 'staging');

        if ($authHeader !== null) {
            $request->headers->set('Authorization', $authHeader);
        }

        return $request;
    }

    private function buildSdkKey(string $projectId, string $environment): SdkKey
    {
        return new SdkKey(
            id: 'key-id-1', name: 'test', keyPrefix: 'vff_srv_valid',
            keyHash: hash('sha256', 'dummy'), kind: SdkKey::KIND_SERVER,
            projectId: $projectId, environment: $environment,
            createdAt: new \DateTimeImmutable(), createdBy: 'u',
        );
    }
}
