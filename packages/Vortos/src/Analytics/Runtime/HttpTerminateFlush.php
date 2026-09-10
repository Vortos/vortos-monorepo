<?php

declare(strict_types=1);

namespace Vortos\Analytics\Runtime;

use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Http\Contract\TerminableMiddlewareInterface;
use Vortos\Http\Request;

/**
 * Ships whatever a request captured, once that request's response has already been sent.
 *
 * ## Why a terminable middleware and not an event subscriber
 *
 * Because an event subscriber does not work here, and did not work for a long time without
 * anyone noticing. `Vortos\Http\Kernel::terminate()` is the whole of the terminate path:
 *
 *     public function terminate(Request $request, SymfonyResponse $response): void
 *     {
 *         foreach ($this->terminableMiddleware as $middleware) {
 *             $middleware->terminate($request, $response);
 *         }
 *     }
 *
 * It dispatches nothing, so `KernelEvents::TERMINATE` — which analytics previously relied on
 * — is never fired. Implementing {@see TerminableMiddlewareInterface} is what the kernel
 * actually calls, and `RegisterTerminablePass` collects every service that implements it
 * with no further wiring. Same shape the OTel log flush already uses.
 *
 * ## Why the bug hid for so long
 *
 * {@see BatchingAnalytics} flushes on its own when its buffer reaches `flushAt` (100 by
 * default). With the per-request flush missing, delivery therefore happened in round hundreds
 * during busy periods and not at all during quiet ones. Production showed exactly 200 events
 * across two weeks and then nothing, while a long-lived FrankenPHP worker held every
 * subsequent event waiting for a hundredth sibling. Nothing errored; the data was simply
 * unbounded-ly late, which for analytics is indistinguishable from lost.
 *
 * ## Cost
 *
 * This runs after the response is sent, so the outbound HTTP call to the analytics backend
 * is off the user's critical path. It is a real call per request that captured something,
 * which is the price of events arriving when they happened; an application that would rather
 * batch can raise `ANALYTICS_BATCH_FLUSH_AT`, or turn on the spool and drain it out of band.
 *
 * Lives beside {@see FlushOnTerminateSubscriber} rather than inside it because this class
 * needs `vortos-http` and analytics must remain usable in an application that has none.
 * Registered only when that interface is present.
 */
final class HttpTerminateFlush implements TerminableMiddlewareInterface
{
    public function __construct(private readonly AnalyticsInterface $analytics) {}

    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->analytics->flush();
        } catch (Throwable) {
            // Intentionally swallowed. The response has already been sent, so there is
            // nobody left to tell, and telemetry must never become a second failure.
        }
    }
}
