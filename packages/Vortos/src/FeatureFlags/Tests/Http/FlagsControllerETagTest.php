<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Http;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagRegistryInterface;
use Vortos\FeatureFlags\Http\DefaultFlagContextResolver;
use Vortos\FeatureFlags\Http\FlagContextResolverInterface;
use Vortos\FeatureFlags\Http\FlagsController;
use Vortos\Http\Request;

final class FlagsControllerETagTest extends TestCase
{
    private const VERSION = 'v1:abcdef1234567890';

    public function test_response_includes_etag_header(): void
    {
        $controller = $this->controller();
        $request    = Request::create('/api/flags', 'GET');

        $response = $controller($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('"' . self::VERSION . '"', $response->headers->get('ETag'));
    }

    public function test_matching_etag_returns_304(): void
    {
        $controller = $this->controller();
        $request    = Request::create('/api/flags', 'GET');
        $request->headers->set('If-None-Match', '"' . self::VERSION . '"');

        $response = $controller($request);

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame('"' . self::VERSION . '"', $response->headers->get('ETag'));
    }

    public function test_non_matching_etag_returns_200(): void
    {
        $controller = $this->controller();
        $request    = Request::create('/api/flags', 'GET');
        $request->headers->set('If-None-Match', '"v1:different"');

        $response = $controller($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_wildcard_etag_returns_304(): void
    {
        $controller = $this->controller();
        $request    = Request::create('/api/flags', 'GET');
        $request->headers->set('If-None-Match', '*');

        $response = $controller($request);

        $this->assertSame(304, $response->getStatusCode());
    }

    public function test_etag_match_among_multiple_values(): void
    {
        $controller = $this->controller();
        $request    = Request::create('/api/flags', 'GET');
        $request->headers->set('If-None-Match', '"v1:other", "' . self::VERSION . '"');

        $response = $controller($request);

        $this->assertSame(304, $response->getStatusCode());
    }

    public function test_no_if_none_match_header_returns_200(): void
    {
        $controller = $this->controller();
        $request    = Request::create('/api/flags', 'GET');

        $response = $controller($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_etag_is_context_specific(): void
    {
        // Different contexts produce different versions → ETags differ per context
        $registry = $this->createMock(FlagRegistryInterface::class);
        $registry->method('allForContext')
            ->willReturnCallback(fn(FlagContext $ctx) => [
                'flags'    => [],
                'variants' => [],
                'payloads' => [],
                'version'  => 'v1:' . ($ctx->userId ?? 'anon'),
            ]);

        $contextResolver = $this->createMock(FlagContextResolverInterface::class);
        $contextResolver->method('resolve')
            ->willReturnCallback(fn(Request $r) => new FlagContext($r->headers->get('X-User-Id')));

        $controller = new FlagsController($registry, $contextResolver);

        $req1 = Request::create('/api/flags');
        $req1->headers->set('X-User-Id', 'user-a');
        $resp1 = $controller($req1);

        $req2 = Request::create('/api/flags');
        $req2->headers->set('X-User-Id', 'user-b');
        $resp2 = $controller($req2);

        $this->assertNotSame($resp1->headers->get('ETag'), $resp2->headers->get('ETag'));
    }

    private function controller(): FlagsController
    {
        $registry = $this->createMock(FlagRegistryInterface::class);
        $registry->method('allForContext')->willReturn([
            'flags'    => ['my-flag'],
            'variants' => [],
            'payloads' => [],
            'version'  => self::VERSION,
        ]);

        $contextResolver = $this->createMock(FlagContextResolverInterface::class);
        $contextResolver->method('resolve')->willReturn(new FlagContext('u1'));

        return new FlagsController($registry, $contextResolver);
    }
}
