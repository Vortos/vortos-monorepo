<?php

declare(strict_types=1);

namespace Vortos\Auth\Tracing;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\EventListener\TracingMiddleware;
use Vortos\Tenant\TenantContext;
use Vortos\Tracing\Contract\SpanInterface;

/**
 * Stamps the acting identity onto the request span.
 *
 * Without this a trace can say an endpoint was slow but never who it was slow for, so a complaint
 * from one customer can only be investigated by guessing from route and timing. With it,
 * "everything this account did in the last hour" is a filter.
 *
 * ## What is deliberately NOT recorded
 *
 * Opaque internal identifiers only — the user id and the tenant id. Never an email address, name,
 * or anything else that identifies a person to somebody who does not already hold this
 * application's database. A user id is meaningless outside it; an email is not, and it would be
 * leaving our infrastructure for a third-party store.
 *
 * That distinction is the whole design. It keeps trace attributes pseudonymous, which is what makes
 * a short retention window and a processor agreement a sufficient control rather than a fig leaf.
 * The collector carries a redaction pass over the traces pipeline as a backstop for anything that
 * slips through some other span.
 *
 * ## Why here and not in TracingMiddleware
 *
 * The span is opened before authentication runs, so the identity is not knowable at that point.
 * This sits at 675 — inside AuthMiddleware (700) and TenantContextMiddleware (680), so both have
 * resolved — and enriches the span the outer middleware already created.
 *
 * ## Why traces and not metrics
 *
 * A span attribute is a field on one event. A metric label is a whole new time series per distinct
 * value, so a user id there would mean one series per user and an unusable metrics store. That is
 * why the collector strips user identifiers from the metrics pipeline and why nothing here should
 * ever be promoted to a metric label.
 */
#[AsMiddleware(order: 675)]
final class IdentityTracingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CurrentUserProvider $currentUser,
        private readonly ?TenantContext $tenant = null,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $span = $request->attributes->get(TracingMiddleware::SPAN_ATTRIBUTE);

        if ($span instanceof SpanInterface) {
            $this->stamp($span);
        }

        return $next($request);
    }

    private function stamp(SpanInterface $span): void
    {
        // Anonymous requests are left unstamped rather than tagged "anonymous": the absence of the
        // attribute already says it, and a constant value would only add noise to every span.
        $identity = $this->currentUser->get();

        if ($identity->isAuthenticated() && $identity->id() !== '') {
            $span->addAttribute('user.id', $identity->id());
        }

        // Tenant is recorded even when the request is unauthenticated — public applicant pages are
        // still scoped to an organisation, and that is exactly when knowing which one matters.
        $tenantId = $this->tenant?->tenantId();

        if (is_string($tenantId) && $tenantId !== '') {
            $span->addAttribute('tenant.id', $tenantId);
        }
    }
}
