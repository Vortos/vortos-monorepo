<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Http;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FlagRegistryInterface;
use Vortos\FeatureFlags\Http\FlagBootstrapController;
use Vortos\Http\Request;

final class FlagBootstrapControllerTest extends TestCase
{
    private const VERSION = 'v1:bootstrap1234567';

    public function test_returns_flags_without_rules_or_pii(): void
    {
        $controller = $this->controller();
        $request    = Request::create('/api/flags/bootstrap');

        $response = $controller($request);
        $data     = json_decode($response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('flags', $data);
        $this->assertArrayHasKey('variants', $data);
        $this->assertArrayHasKey('payloads', $data);
        $this->assertArrayHasKey('version', $data);
        // Must NOT contain rules, segments, user lists, etc.
        $this->assertArrayNotHasKey('rules', $data);
        $this->assertArrayNotHasKey('segments', $data);
        $this->assertArrayNotHasKey('users', $data);
    }

    public function test_includes_cache_control_headers(): void
    {
        $controller = $this->controller();
        $request    = Request::create('/api/flags/bootstrap');

        $response = $controller($request);

        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=60', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('stale-while-revalidate=30', $response->headers->get('Cache-Control'));
    }

    public function test_includes_etag_header(): void
    {
        $controller = $this->controller();
        $request    = Request::create('/api/flags/bootstrap');

        $response = $controller($request);

        $this->assertSame('"' . self::VERSION . '"', $response->headers->get('ETag'));
    }

    public function test_matching_etag_returns_304(): void
    {
        $controller = $this->controller();
        $request    = Request::create('/api/flags/bootstrap');
        $request->headers->set('If-None-Match', '"' . self::VERSION . '"');

        $response = $controller($request);

        $this->assertSame(304, $response->getStatusCode());
    }

    public function test_non_matching_etag_returns_200(): void
    {
        $controller = $this->controller();
        $request    = Request::create('/api/flags/bootstrap');
        $request->headers->set('If-None-Match', '"v1:stale"');

        $response = $controller($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_includes_vary_header(): void
    {
        $controller = $this->controller();
        $request    = Request::create('/api/flags/bootstrap');

        $response = $controller($request);

        $this->assertSame('Accept-Encoding', $response->headers->get('Vary'));
    }

    public function test_custom_cache_durations(): void
    {
        $controller = new FlagBootstrapController(
            $this->registry(),
            maxAgeSeconds: 120,
            staleWhileRevalidateSeconds: 60,
        );
        $request = Request::create('/api/flags/bootstrap');

        $response = $controller($request);

        $this->assertStringContainsString('max-age=120', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('stale-while-revalidate=60', $response->headers->get('Cache-Control'));
    }

    private function controller(): FlagBootstrapController
    {
        return new FlagBootstrapController($this->registry());
    }

    private function registry(): FlagRegistryInterface
    {
        $registry = $this->createMock(FlagRegistryInterface::class);
        $registry->method('allForContext')->willReturn([
            'flags'    => ['my-flag'],
            'variants' => ['my-flag' => 'treatment'],
            'payloads' => ['my-flag' => ['color' => 'blue']],
            'version'  => self::VERSION,
        ]);

        return $registry;
    }
}
