<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\Audit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Vortos\Auth\Audit\AuditEntry;
use Vortos\Auth\Audit\Contract\AuditStoreInterface;
use Vortos\Auth\Audit\Middleware\AuditMiddleware;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;

final class AuditMiddlewareTest extends TestCase
{
    private function makeProvider(bool $authenticated = true): CurrentUserProvider
    {
        $adapter = new ArrayAdapter();
        $identity = $authenticated ? new UserIdentity('user-1', []) : new AnonymousIdentity();
        $adapter->set('auth:identity', $identity);
        return new CurrentUserProvider($adapter);
    }

    private function makeEvent(string $controller, array $attributes = []): RequestEvent
    {
        $request = Request::create('/test');
        $request->attributes->set('_controller', $controller);
        foreach ($attributes as $k => $v) {
            $request->attributes->set($k, $v);
        }
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    public function test_records_audit_entry_when_route_matches(): void
    {
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->once())->method('record');

        $routeMap = ['App\TestCtrl' => [['action' => 'document.viewed', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap);
        $middleware->onKernelRequest($this->makeEvent('App\TestCtrl'));
    }

    public function test_does_not_record_for_anonymous_user(): void
    {
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->never())->method('record');

        $routeMap = ['App\TestCtrl' => [['action' => 'document.viewed', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(false), $store, $routeMap);
        $middleware->onKernelRequest($this->makeEvent('App\TestCtrl'));
    }

    public function test_does_not_record_when_no_store(): void
    {
        $routeMap = ['App\TestCtrl' => [['action' => 'document.viewed', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(), null, $routeMap);
        // Should not throw
        $middleware->onKernelRequest($this->makeEvent('App\TestCtrl'));
        $this->assertTrue(true);
    }

    public function test_does_not_record_when_no_route_map(): void
    {
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->never())->method('record');

        $middleware = new AuditMiddleware($this->makeProvider(), $store, []);
        $middleware->onKernelRequest($this->makeEvent('App\TestCtrl'));
    }

    public function test_captures_included_route_params(): void
    {
        $captured = null;
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->once())
            ->method('record')
            ->willReturnCallback(function(AuditEntry $entry) use (&$captured) {
                $captured = $entry->metadata;
            });

        $routeMap = ['App\TestCtrl' => [['action' => 'document.deleted', 'include' => ['id', 'reason']]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap);
        $middleware->onKernelRequest($this->makeEvent('App\TestCtrl', ['id' => 'doc-123', 'reason' => 'spam']));

        $this->assertSame('doc-123', $captured['id']);
        $this->assertSame('spam', $captured['reason']);
    }

    public function test_audit_failure_does_not_block_request(): void
    {
        $store = $this->createMock(AuditStoreInterface::class);
        $store->method('record')->willThrowException(new \RuntimeException('DB down'));

        $routeMap = ['App\TestCtrl' => [['action' => 'document.viewed', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap);
        $event = $this->makeEvent('App\TestCtrl');
        $middleware->onKernelRequest($event);

        // No exception thrown, no response set
        $this->assertNull($event->getResponse());
    }

    public function test_skips_subrequests(): void
    {
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->never())->method('record');

        $routeMap = ['App\TestCtrl' => [['action' => 'document.viewed', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap);

        $request = Request::create('/test');
        $request->attributes->set('_controller', 'App\TestCtrl');
        $kernel = $this->createMock(HttpKernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);
        $middleware->onKernelRequest($event);
    }
}
