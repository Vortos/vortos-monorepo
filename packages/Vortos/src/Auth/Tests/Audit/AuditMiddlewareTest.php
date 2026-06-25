<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Audit;

use PHPUnit\Framework\TestCase;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Auth\Audit\AuditEntry;
use Vortos\Auth\Audit\AuditFailureMode;
use Vortos\Auth\Audit\Contract\AuditStoreInterface;
use Vortos\Auth\Audit\Integrity\AuthAuditHashChain;
use Vortos\Auth\Audit\Integrity\InMemoryChainStateStore;
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

    // -------------------------------------------------------------------------
    // Core audit recording
    // -------------------------------------------------------------------------

    public function test_records_audit_entry_when_route_matches(): void
    {
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->once())->method('record');

        $routeMap = ['App\TestCtrl' => [['action' => 'document.viewed', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap);
        $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
    }

    public function test_records_audit_entry_for_anonymous_user(): void
    {
        $captured = null;
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->once())
            ->method('record')
            ->willReturnCallback(function(AuditEntry $entry) use (&$captured) {
                $captured = $entry;
            });

        $routeMap = ['App\TestCtrl' => [['action' => 'login.failed', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(false), $store, $routeMap);
        $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());

        $this->assertSame('anonymous', $captured->userId);
    }

    // -------------------------------------------------------------------------
    // Failure modes
    // -------------------------------------------------------------------------

    public function test_returns_503_when_no_store_and_fail_closed(): void
    {
        $routeMap = ['App\TestCtrl' => [['action' => 'document.viewed', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(), null, $routeMap, AuditFailureMode::FailClosed);
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $this->assertSame(503, $response->getStatusCode());
    }

    public function test_passes_through_when_no_store_and_fail_open(): void
    {
        $routeMap = ['App\TestCtrl' => [['action' => 'document.viewed', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(), null, $routeMap, AuditFailureMode::FailOpen);
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_returns_503_on_store_error_when_fail_closed(): void
    {
        $store = $this->createMock(AuditStoreInterface::class);
        $store->method('record')->willThrowException(new \RuntimeException('DB down'));

        $routeMap = ['App\TestCtrl' => [['action' => 'document.viewed', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap, AuditFailureMode::FailClosed);
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());

        $this->assertSame(503, $response->getStatusCode());
    }

    public function test_passes_through_on_store_error_when_fail_open(): void
    {
        $store = $this->createMock(AuditStoreInterface::class);
        $store->method('record')->willThrowException(new \RuntimeException('DB down'));

        $routeMap = ['App\TestCtrl' => [['action' => 'document.viewed', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap, AuditFailureMode::FailOpen);
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());

        $this->assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Routing
    // -------------------------------------------------------------------------

    public function test_does_not_record_when_no_route_map(): void
    {
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->never())->method('record');

        $middleware = new AuditMiddleware($this->makeProvider(), $store, []);
        $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
    }

    public function test_passes_through_when_route_not_in_map(): void
    {
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->never())->method('record');

        $routeMap = ['App\OtherCtrl' => [['action' => 'x', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap);
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_no_store_passes_through_when_route_not_in_map(): void
    {
        $middleware = new AuditMiddleware($this->makeProvider(), null, [], AuditFailureMode::FailClosed);
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $this->assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Metadata capture
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // #16 — Metadata sanitization hardening
    // -------------------------------------------------------------------------

    public function test_strips_sensitive_params_from_metadata(): void
    {
        $captured = null;
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->once())
            ->method('record')
            ->willReturnCallback(function(AuditEntry $entry) use (&$captured) {
                $captured = $entry->metadata;
            });

        $routeMap = ['App\TestCtrl' => [['action' => 'login', 'include' => ['username', 'password', 'token']]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap);
        $middleware->handle(
            $this->makeRequest('App\TestCtrl', ['username' => 'admin', 'password' => 'secret123', 'token' => 'abc']),
            $this->next(),
        );

        $this->assertSame('admin', $captured['username']);
        $this->assertArrayNotHasKey('password', $captured);
        $this->assertArrayNotHasKey('token', $captured);
    }

    public function test_truncates_long_metadata_values(): void
    {
        $captured = null;
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->once())
            ->method('record')
            ->willReturnCallback(function(AuditEntry $entry) use (&$captured) {
                $captured = $entry->metadata;
            });

        $longValue = str_repeat('x', 2000);
        $routeMap = ['App\TestCtrl' => [['action' => 'data.import', 'include' => ['payload']]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap);
        $middleware->handle(
            $this->makeRequest('App\TestCtrl', ['payload' => $longValue]),
            $this->next(),
        );

        $this->assertLessThanOrEqual(1030, strlen($captured['payload']));
    }

    public function test_non_scalar_metadata_values_are_redacted(): void
    {
        $captured = null;
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->once())
            ->method('record')
            ->willReturnCallback(function(AuditEntry $entry) use (&$captured) {
                $captured = $entry->metadata;
            });

        $routeMap = ['App\TestCtrl' => [['action' => 'import', 'include' => ['data', 'count']]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap);
        $middleware->handle(
            $this->makeRequest('App\TestCtrl', ['data' => ['nested' => 'secret'], 'count' => 42]),
            $this->next(),
        );

        $this->assertSame('[redacted:non-scalar]', $captured['data']);
        $this->assertSame(42, $captured['count']);
    }

    public function test_control_characters_stripped_from_metadata(): void
    {
        $captured = null;
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->once())
            ->method('record')
            ->willReturnCallback(function(AuditEntry $entry) use (&$captured) {
                $captured = $entry->metadata;
            });

        $routeMap = ['App\TestCtrl' => [['action' => 'log.inject', 'include' => ['name']]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap);
        $middleware->handle(
            $this->makeRequest('App\TestCtrl', ['name' => "admin\x00\x1F\x7F\nlegit"]),
            $this->next(),
        );

        $this->assertSame("admin\nlegit", $captured['name']);
    }

    public function test_resource_id_length_capped(): void
    {
        $captured = null;
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->once())
            ->method('record')
            ->willReturnCallback(function(AuditEntry $entry) use (&$captured) {
                $captured = $entry;
            });

        $routeMap = ['App\TestCtrl' => [['action' => 'view', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap);
        $middleware->handle(
            $this->makeRequest('App\TestCtrl', ['id' => str_repeat('x', 500)]),
            $this->next(),
        );

        $this->assertSame(255, strlen($captured->resourceId));
    }

    public function test_non_scalar_resource_id_is_null(): void
    {
        $captured = null;
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->once())
            ->method('record')
            ->willReturnCallback(function(AuditEntry $entry) use (&$captured) {
                $captured = $entry;
            });

        $routeMap = ['App\TestCtrl' => [['action' => 'view', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap);
        $middleware->handle(
            $this->makeRequest('App\TestCtrl', ['id' => ['nested' => 'bad']]),
            $this->next(),
        );

        $this->assertNull($captured->resourceId);
    }

    public function test_invalid_metadata_key_is_skipped(): void
    {
        $captured = null;
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->once())
            ->method('record')
            ->willReturnCallback(function(AuditEntry $entry) use (&$captured) {
                $captured = $entry->metadata;
            });

        $routeMap = ['App\TestCtrl' => [['action' => 'x', 'include' => ['valid_key', '../etc/passwd', 'also.valid']]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap);
        $middleware->handle(
            $this->makeRequest('App\TestCtrl', ['valid_key' => 'ok', '../etc/passwd' => 'bad', 'also.valid' => 'yes']),
            $this->next(),
        );

        $this->assertSame('ok', $captured['valid_key']);
        $this->assertArrayNotHasKey('../etc/passwd', $captured);
        $this->assertSame('yes', $captured['also.valid']);
    }

    public function test_boolean_metadata_preserved(): void
    {
        $captured = null;
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->once())
            ->method('record')
            ->willReturnCallback(function(AuditEntry $entry) use (&$captured) {
                $captured = $entry->metadata;
            });

        $routeMap = ['App\TestCtrl' => [['action' => 'toggle', 'include' => ['enabled']]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap);
        $middleware->handle(
            $this->makeRequest('App\TestCtrl', ['enabled' => true]),
            $this->next(),
        );

        $this->assertTrue($captured['enabled']);
    }

    // -------------------------------------------------------------------------
    // #15 — Tamper-evident chain integration
    // -------------------------------------------------------------------------

    public function test_entry_is_chained_when_integrity_configured(): void
    {
        $captured = null;
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->once())
            ->method('record')
            ->willReturnCallback(function(AuditEntry $entry) use (&$captured) {
                $captured = $entry;
            });

        $routeMap = ['App\TestCtrl' => [['action' => 'view', 'include' => []]]];
        $middleware = new AuditMiddleware(
            $this->makeProvider(),
            $store,
            $routeMap,
            hashChain: new AuthAuditHashChain(),
            chainStateStore: new InMemoryChainStateStore(),
            auditHmacKey: bin2hex(random_bytes(32)),
        );
        $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());

        $this->assertTrue($captured->isChained());
        $this->assertSame(0, $captured->sequence);
        $this->assertNotNull($captured->contentHash);
        $this->assertNotNull($captured->signature);
    }

    public function test_chained_entries_link_sequentially(): void
    {
        $entries = [];
        $store = $this->createMock(AuditStoreInterface::class);
        $store->method('record')
            ->willReturnCallback(function(AuditEntry $entry) use (&$entries) {
                $entries[] = $entry;
            });

        $routeMap = ['App\TestCtrl' => [['action' => 'view', 'include' => []]]];
        $middleware = new AuditMiddleware(
            $this->makeProvider(),
            $store,
            $routeMap,
            hashChain: new AuthAuditHashChain(),
            chainStateStore: new InMemoryChainStateStore(),
            auditHmacKey: bin2hex(random_bytes(32)),
        );

        $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());

        $this->assertCount(3, $entries);
        $this->assertSame(0, $entries[0]->sequence);
        $this->assertSame(1, $entries[1]->sequence);
        $this->assertSame(2, $entries[2]->sequence);
        $this->assertSame($entries[0]->contentHash, $entries[1]->prevHash);
        $this->assertSame($entries[1]->contentHash, $entries[2]->prevHash);
    }

    public function test_chain_failure_returns_503_when_fail_closed(): void
    {
        $chainStore = $this->createMock(\Vortos\Auth\Audit\Integrity\ChainStateStoreInterface::class);
        $chainStore->method('appendChained')
            ->willThrowException(new \RuntimeException('Redis down'));

        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->never())->method('record');

        $routeMap = ['App\TestCtrl' => [['action' => 'view', 'include' => []]]];
        $middleware = new AuditMiddleware(
            $this->makeProvider(),
            $store,
            $routeMap,
            AuditFailureMode::FailClosed,
            hashChain: new AuthAuditHashChain(),
            chainStateStore: $chainStore,
            auditHmacKey: 'some-key',
        );

        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $this->assertSame(503, $response->getStatusCode());
    }

    public function test_without_hmac_key_entries_are_not_chained(): void
    {
        $captured = null;
        $store = $this->createMock(AuditStoreInterface::class);
        $store->expects($this->once())
            ->method('record')
            ->willReturnCallback(function(AuditEntry $entry) use (&$captured) {
                $captured = $entry;
            });

        $routeMap = ['App\TestCtrl' => [['action' => 'view', 'include' => []]]];
        $middleware = new AuditMiddleware($this->makeProvider(), $store, $routeMap);
        $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());

        $this->assertFalse($captured->isChained());
    }
}
