<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vortos\FeatureFlags\Delivery\FlagChangeNotifierInterface;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlagsAdmin\Http\Controller\FlagAdminStreamController;
use Vortos\Http\Request;

final class FlagAdminStreamControllerTest extends TestCase
{
    private FlagChangeNotifierInterface $notifier;
    private FlagAdminStreamController $controller;

    protected function setUp(): void
    {
        $this->notifier   = $this->createMock(FlagChangeNotifierInterface::class);
        $this->controller = new FlagAdminStreamController(
            notifier:     $this->notifier,
            scopeContext: new FlagScopeContext(),
        );
    }

    public function test_returns_streamed_response(): void
    {
        $request  = Request::create('/admin/flags/stream', 'GET');
        $response = ($this->controller)($request);

        $this->assertInstanceOf(StreamedResponse::class, $response);
    }

    public function test_sets_sse_headers(): void
    {
        $request  = Request::create('/admin/flags/stream', 'GET');
        $response = ($this->controller)($request);

        $this->assertSame('text/event-stream', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    public function test_accepts_env_query_parameter(): void
    {
        $request  = Request::create('/admin/flags/stream', 'GET', ['env' => 'staging']);
        $response = ($this->controller)($request);

        $this->assertInstanceOf(StreamedResponse::class, $response);
    }

    public function test_defaults_to_production_env(): void
    {
        $scopeContext = new FlagScopeContext();

        $controller = new FlagAdminStreamController(
            notifier:     $this->notifier,
            scopeContext: $scopeContext,
        );

        $request  = Request::create('/admin/flags/stream', 'GET');
        $response = $controller($request);

        $this->assertInstanceOf(StreamedResponse::class, $response);
    }
}
