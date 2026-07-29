<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\Tracing;

use PHPUnit\Framework\TestCase;
use Vortos\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Tracing\IdentityTracingMiddleware;
use Vortos\Http\EventListener\TracingMiddleware;
use Vortos\Tenant\TenantContext;
use Vortos\Tracing\Contract\SpanInterface;

final class IdentityTracingMiddlewareTest extends TestCase
{
    private function recordingSpan(): SpanInterface
    {
        return new class implements SpanInterface {
            /** @var array<string, mixed> */
            public array $recorded = [];

            public function addAttribute(string $key, mixed $value): void
            {
                $this->recorded[$key] = $value;
            }

            public function end(): void {}

            public function recordException(\Throwable $e): void {}

            public function setStatus(string $status): void {}
        };
    }

    private function provider(?UserIdentityInterface $identity): CurrentUserProvider
    {
        $adapter = new ArrayAdapter();

        if ($identity !== null) {
            $adapter->set('auth:identity', $identity);
        }

        return new CurrentUserProvider($adapter);
    }

    private function identity(string $id, bool $authenticated = true): UserIdentityInterface
    {
        return new class ($id, $authenticated) implements UserIdentityInterface {
            public function __construct(private string $id, private bool $auth) {}

            public function id(): string { return $this->id; }

            public function roles(): array { return []; }

            public function isAuthenticated(): bool { return $this->auth; }

            public function hasRole(string $role): bool { return false; }

            public function getAttribute(string $key, mixed $default = null): mixed { return $default; }

            public function getClaims(): array { return []; }
        };
    }

    private function dispatch(IdentityTracingMiddleware $mw, ?SpanInterface $span): Response
    {
        $request = new Request();

        if ($span !== null) {
            $request->attributes->set(TracingMiddleware::SPAN_ATTRIBUTE, $span);
        }

        return $mw->handle($request, static fn (Request $r): Response => new Response('ok'));
    }

    public function test_stamps_the_user_id_on_the_span(): void
    {
        $span = $this->recordingSpan();

        $this->dispatch(new IdentityTracingMiddleware($this->provider($this->identity('user-123'))), $span);

        self::assertSame('user-123', $span->recorded['user.id'] ?? null);
    }

    public function test_stamps_the_tenant_id(): void
    {
        $span = $this->recordingSpan();
        $tenant = new TenantContext();
        $tenant->set('org-abc');

        $this->dispatch(
            new IdentityTracingMiddleware($this->provider($this->identity('user-1')), $tenant),
            $span,
        );

        self::assertSame('org-abc', $span->recorded['tenant.id'] ?? null);
    }

    /**
     * The privacy contract: opaque identifiers only. An email on a span would leave this
     * application's infrastructure for a third-party store, which a user id does not meaningfully
     * do — it is inert without the database here.
     */
    public function test_records_identifiers_only_and_never_anything_else(): void
    {
        $span = $this->recordingSpan();
        $tenant = new TenantContext();
        $tenant->set('org-abc');

        $this->dispatch(
            new IdentityTracingMiddleware($this->provider($this->identity('user-1')), $tenant),
            $span,
        );

        self::assertSame(['user.id', 'tenant.id'], array_keys($span->recorded));
    }

    public function test_leaves_anonymous_requests_unstamped(): void
    {
        $span = $this->recordingSpan();

        $this->dispatch(new IdentityTracingMiddleware($this->provider(null)), $span);

        self::assertArrayNotHasKey('user.id', $span->recorded);
    }

    public function test_does_not_stamp_an_unauthenticated_identity(): void
    {
        $span = $this->recordingSpan();

        $this->dispatch(
            new IdentityTracingMiddleware($this->provider($this->identity('x', authenticated: false))),
            $span,
        );

        self::assertArrayNotHasKey('user.id', $span->recorded);
    }

    public function test_still_records_the_tenant_when_the_request_is_anonymous(): void
    {
        // Public applicant pages are unauthenticated but still scoped to an organisation, and that
        // is exactly when knowing which one matters.
        $span = $this->recordingSpan();
        $tenant = new TenantContext();
        $tenant->set('org-public');

        $this->dispatch(new IdentityTracingMiddleware($this->provider(null), $tenant), $span);

        self::assertSame('org-public', $span->recorded['tenant.id'] ?? null);
        self::assertArrayNotHasKey('user.id', $span->recorded);
    }

    public function test_passes_through_when_there_is_no_span(): void
    {
        $response = $this->dispatch(new IdentityTracingMiddleware($this->provider($this->identity('u'))), null);

        self::assertSame('ok', $response->getContent());
    }
}
