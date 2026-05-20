<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\Audit;

use PHPUnit\Framework\TestCase;
use Vortos\Http\Request;
use Vortos\Http\Response;
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

    private function makeRequest(string $controller, array $attributes = []): Request
    {
        $request = Request::create('/test');
        $request->attributes->set('_controller', $controller);
        foreach ($attributes as $k => $v) {
            $request->attributes->set($k, $v);
        }
        return $request;
    }

    private function next(int $statusCode = 200): \Closure
    {
        return fn(Request $r) => new Response('ok', $statusCode);
    }

    public function test_records_audit_entry_when_route_matches(): void
    {
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->once())->method('record');

        $routeMap = ['App\TestCtrl' => [['action' => 'document.viewed', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap);
        $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
    }

    public function test_does_not_record_for_anonymous_user(): void
    {
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->never())->method('record');

        $routeMap = ['App\TestCtrl' => [['action' => 'document.viewed', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(false), $store, $routeMap);
        $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
    }

    public function test_does_not_record_when_no_store(): void
    {
        $routeMap = ['App\TestCtrl' => [['action' => 'document.viewed', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(), null, $routeMap);
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_does_not_record_when_no_route_map(): void
    {
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->never())->method('record');

        $middleware = new AuditMiddleware($this->makeProvider(), $store, []);
        $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
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
        $middleware->handle(
            $this->makeRequest('App\TestCtrl', ['id' => 'doc-123', 'reason' => 'spam']),
            $this->next(),
        );

        $this->assertSame('doc-123', $captured['id']);
        $this->assertSame('spam', $captured['reason']);
    }

    public function test_records_response_status_code_in_metadata(): void
    {
        $captured = null;
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->once())
            ->method('record')
            ->willReturnCallback(function(AuditEntry $entry) use (&$captured) {
                $captured = $entry->metadata;
            });

        $routeMap = ['App\TestCtrl' => [['action' => 'document.viewed', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap);
        $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next(201));

        $this->assertSame(201, $captured['_status']);
    }

    public function test_audit_failure_does_not_affect_response(): void
    {
        $store = $this->createMock(AuditStoreInterface::class);
        $store->method('record')->willThrowException(new \RuntimeException('DB down'));

        $routeMap = ['App\TestCtrl' => [['action' => 'document.viewed', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap);
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next(200));

        $this->assertSame(200, $response->getStatusCode());
    }
}
