<?php

declare(strict_types=1);

namespace Vortos\Tests\FeatureFlags;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
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

    public function test_passes_through_when_no_controller_in_request(): void
    {
        $registry = $this->createMock(FlagRegistryInterface::class);
        $registry->expects($this->never())->method('isEnabled');

        $middleware = new FeatureFlagMiddleware($registry, $this->resolver(), self::FLAG_MAP);
        $middleware->onRequest($this->event(new Request()));
    }

    public function test_passes_through_for_controller_without_attribute(): void
    {
        $registry = $this->createMock(FlagRegistryInterface::class);
        $registry->expects($this->never())->method('isEnabled');

        $middleware = new FeatureFlagMiddleware($registry, $this->resolver(), self::FLAG_MAP);
        $middleware->onRequest($this->event($this->requestFor(UnflaggedController::class)));
    }

    public function test_passes_through_when_flag_is_enabled(): void
    {
        $registry = $this->createMock(FlagRegistryInterface::class);
        $registry->method('isEnabled')->with('new-dashboard')->willReturn(true);

        $middleware = new FeatureFlagMiddleware($registry, $this->resolver(), self::FLAG_MAP);

        $middleware->onRequest($this->event($this->requestFor(FlaggedController::class)));
        $this->assertTrue(true);
    }

    public function test_throws_when_flag_is_disabled(): void
    {
        $registry = $this->createMock(FlagRegistryInterface::class);
        $registry->method('isEnabled')->with('new-dashboard')->willReturn(false);

        $middleware = new FeatureFlagMiddleware($registry, $this->resolver(), self::FLAG_MAP);

        $this->expectException(FeatureNotAvailableException::class);
        $middleware->onRequest($this->event($this->requestFor(FlaggedController::class)));
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

        $middleware->onRequest($this->event($request));
        $middleware->onRequest($this->event($request));
        $middleware->onRequest($this->event($request));
    }

    public function test_empty_flag_map_skips_all_flag_checks(): void
    {
        $registry = $this->createMock(FlagRegistryInterface::class);
        $registry->expects($this->never())->method('isEnabled');

        $middleware = new FeatureFlagMiddleware($registry, $this->resolver(), []);
        $middleware->onRequest($this->event($this->requestFor(FlaggedController::class)));
    }

    public function test_subscribed_to_kernel_request(): void
    {
        $events = FeatureFlagMiddleware::getSubscribedEvents();
        $this->assertArrayHasKey('kernel.request', $events);
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

    private function event(Request $request): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
