<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\FeatureFlags\Attribute\RequiresFlag;
use Vortos\FeatureFlags\Exception\FeatureNotAvailableException;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagRegistryInterface;
use Vortos\FeatureFlags\Http\FlagContextResolverInterface;
use Vortos\FeatureFlags\Http\FeatureFlagMiddleware;

// --- fixtures ---

#[RequiresFlag('new-dashboard')]
final class FlaggedController
{
    public function __invoke(): void {}
}

final class UnflaggedController
{
    public function __invoke(): void {}
}

final class FeatureFlagMiddlewareTest extends TestCase
{
    private const FLAG_MAP = [
        FlaggedController::class . '::__invoke' => 'new-dashboard',
    ];

    private function next(): \Closure
    {
        return fn(Request $r) => new Response('ok', 200);
    }

    public function test_passes_through_when_no_controller_in_request(): void
    {
        $registry = $this->createMock(FlagRegistryInterface::class);
        $registry->expects($this->never())->method('isEnabled');

        $middleware = new FeatureFlagMiddleware($registry, $this->resolver(), self::FLAG_MAP);
        $response = $middleware->handle(new Request(), $this->next());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_passes_through_for_controller_without_attribute(): void
    {
        $registry = $this->createMock(FlagRegistryInterface::class);
        $registry->expects($this->never())->method('isEnabled');

        $middleware = new FeatureFlagMiddleware($registry, $this->resolver(), self::FLAG_MAP);
        $response = $middleware->handle($this->requestFor(UnflaggedController::class), $this->next());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_passes_through_when_flag_is_enabled(): void
    {
        $registry = $this->createMock(FlagRegistryInterface::class);
        $registry->method('isEnabled')->with('new-dashboard')->willReturn(true);

        $middleware = new FeatureFlagMiddleware($registry, $this->resolver(), self::FLAG_MAP);
        $response = $middleware->handle($this->requestFor(FlaggedController::class), $this->next());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_throws_when_flag_is_disabled(): void
    {
        $registry = $this->createMock(FlagRegistryInterface::class);
        $registry->method('isEnabled')->with('new-dashboard')->willReturn(false);

        $middleware = new FeatureFlagMiddleware($registry, $this->resolver(), self::FLAG_MAP);

        $this->expectException(FeatureNotAvailableException::class);
        $middleware->handle($this->requestFor(FlaggedController::class), $this->next());
    }

    public function test_registry_called_on_each_request_via_compiled_map(): void
    {
        $registry = $this->createMock(FlagRegistryInterface::class);
        $registry->expects($this->exactly(3))
            ->method('isEnabled')
            ->with('new-dashboard')
            ->willReturn(true);

        $middleware = new FeatureFlagMiddleware($registry, $this->resolver(), self::FLAG_MAP);
        $request    = $this->requestFor(FlaggedController::class);

        $middleware->handle($request, $this->next());
        $middleware->handle($request, $this->next());
        $middleware->handle($request, $this->next());
    }

    public function test_empty_flag_map_skips_all_flag_checks(): void
    {
        $registry = $this->createMock(FlagRegistryInterface::class);
        $registry->expects($this->never())->method('isEnabled');

        $middleware = new FeatureFlagMiddleware($registry, $this->resolver(), []);
        $middleware->handle($this->requestFor(FlaggedController::class), $this->next());
    }

    private function resolver(): FlagContextResolverInterface
    {
        return new class implements FlagContextResolverInterface {
            public function resolve(Request $request): FlagContext { return new FlagContext(); }
        };
    }

    private function requestFor(string $controllerClass): Request
    {
        $request = new Request();
        $request->attributes->set('_controller', $controllerClass . '::__invoke');
        return $request;
    }
}
