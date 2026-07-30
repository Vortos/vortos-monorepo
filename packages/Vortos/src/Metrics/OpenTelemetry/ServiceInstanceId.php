<?php

declare(strict_types=1);

namespace Vortos\Metrics\OpenTelemetry;

/**
 * Resolves the OpenTelemetry `service.instance.id` for this process — the attribute that keeps one
 * runtime's counters from colliding with another's.
 *
 * ## The bug this exists to prevent
 *
 * Under FrankenPHP worker mode the app runs N long-lived worker *threads*, each with its own PHP
 * interpreter, its own DI container, and therefore its own in-memory `MeterProvider`. With cumulative
 * temporality each thread exports a running total counted from its own start — so thread A might
 * report 40 while thread B reports 7, seconds apart, under an identical resource identity.
 *
 * A Prometheus-compatible backend treats that single series as one counter that keeps jumping
 * backwards, and reads every decrease as a counter reset, re-adding the full value each time. The
 * observed damage on production before this fix:
 *
 *     app_http_requests_total                 sum() =  4,467   increase(…[15m]) = 123,592
 *     app_notification_…_subscriptions_total  sum() =  1,911   increase(…[15m]) =  54,748
 *
 * An increase larger than the counter's own total is impossible, and it is not a rounding artefact:
 * absolute totals were understated (one thread's view won the sample) while every `rate()` and
 * `increase()` was inflated by roughly an order of magnitude. Any alert or panel built on a rate over
 * an app counter was silently wrong.
 *
 * Giving each runtime a distinct `service.instance.id` splits those into one monotonic series per
 * thread, which is precisely what the attribute is specified for. `sum(metric)` then reports the true
 * total and `sum(rate(metric[…]))` the true rate.
 *
 * ## Why not delta temporality instead
 *
 * Delta would also fix it — per-interval deltas from many threads simply add up — but Grafana Cloud /
 * Mimir and the Prometheus OTLP receiver reject delta outright with HTTP 400 "invalid temporality and
 * type combination". Delta would require a collector-side `deltatocumulative` conversion, i.e. moving
 * a correctness guarantee out of the application and into generated infrastructure config that a
 * regeneration can silently drop. See {@see \Vortos\Metrics\Config\MetricTemporality}.
 *
 * ## Cardinality
 *
 * This multiplies series count by the number of live instances, so it is not free. It is affordable
 * here because the default resource already carries `host.name` — the *container id* — which changes
 * on every deploy, so per-deploy series churn already exists; this widens each generation by the
 * thread count rather than introducing a new churn class. Short-lived console one-shots each become
 * their own instance too, which is the honest representation: they really are separate runtimes, and
 * their series simply go stale.
 *
 * Set `OTEL_SERVICE_INSTANCE_ID` (or configure it explicitly) to pin a stable value where a
 * deployment wants one identity per replica instead of one per thread — accepting that counters from
 * threads sharing that id will collide again.
 *
 * ## Not the same answer for every runtime
 *
 * How the discriminator is derived depends on whether statics survive between requests, and getting
 * that backwards turns a bounded fix into an unbounded cardinality leak. See {@see generate()} — the
 * short version is that FrankenPHP worker threads share a PID (so a PID cannot separate them) while
 * php-fpm resets statics per request (so randomness cannot persist for one).
 */
final class ServiceInstanceId
{
    public const ENV_VAR = 'OTEL_SERVICE_INSTANCE_ID';

    /**
     * Bytes of randomness in the generated suffix. Six is ample: this only has to be unique among the
     * handful of threads inside one host, and the host name is already part of the value.
     */
    private const SUFFIX_BYTES = 6;

    /**
     * The resolved id for this process, computed at most once.
     *
     * Deliberate cross-request state, and load-bearing: the value must stay fixed for the lifetime of
     * a worker thread. A value that changed between flushes would make that thread's counter appear to
     * restart from zero on every request — reintroducing exactly the false-reset problem this class
     * exists to remove, in a form that looks like a much stranger bug.
     */
    private static ?string $resolved = null;

    /**
     * @param string $configured explicit value from config; empty string means "not configured"
     */
    public static function resolve(string $configured = ''): string
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        if ($configured !== '') {
            return self::$resolved = $configured;
        }

        $fromEnv = (string) ($_SERVER[self::ENV_VAR] ?? $_ENV[self::ENV_VAR] ?? '');
        if ($fromEnv !== '') {
            return self::$resolved = $fromEnv;
        }

        return self::$resolved = self::generate();
    }

    /**
     * Resets the memoised value. Test-only: production code must never call this, because changing the
     * id mid-process is the failure mode described above.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$resolved = null;
    }

    /**
     * Host name plus a discriminator, chosen by how the runtime keeps state — and the choice matters
     * more than it looks.
     *
     * **Worker mode (FrankenPHP): randomness.** The worker script is executed once per thread and then
     * loops, so a static survives for that thread's whole life; randomness generated at boot is
     * therefore stable per thread and distinct between them. A PID would be useless here, because
     * FrankenPHP worker threads are threads of ONE process and all report the same PID — leaving the
     * exact collision this class exists to fix.
     *
     * **Everything else (php-fpm, CLI): the PID.** Outside worker mode PHP resets statics between
     * requests, so randomness would regenerate on *every request* — turning a bounded fix into an
     * unbounded cardinality leak, one new series per request, which is a worse failure than the bug.
     * A PID is stable for the life of an fpm worker and bounded by `pm.max_children`.
     *
     * Detection reuses the same probe {@see \Vortos\Foundation\Runner} uses to decide whether it is in
     * worker mode, so the two can never disagree about which runtime this is.
     */
    private static function generate(): string
    {
        return self::generateFor(self::isWorkerMode());
    }

    /**
     * The generation rule, with the runtime decision passed in.
     *
     * Split out so both branches can be asserted deterministically. Detecting the runtime inside would
     * mean each branch is only exercised when the test suite happens to run under that runtime — the
     * php-fpm branch would never be covered in CI (which runs CLI), and the worker branch never at all.
     * Given that choosing the wrong branch causes an unbounded cardinality leak rather than a visible
     * error, neither may go untested.
     *
     * @internal
     */
    public static function generateFor(bool $workerMode): string
    {
        $host = gethostname();
        $prefix = is_string($host) && $host !== '' ? $host : 'unknown-host';

        // Worker mode: randomness, because threads share a PID and statics survive the request.
        // Otherwise: the PID, because statics do not survive and randomness would leak a series
        // per request. Full reasoning in {@see generate()}.
        $discriminator = $workerMode
            ? bin2hex(random_bytes(self::SUFFIX_BYTES))
            : (string) getmypid();

        return $prefix . '-' . $discriminator;
    }

    private static function isWorkerMode(): bool
    {
        return function_exists('frankenphp_handle_request');
    }
}
