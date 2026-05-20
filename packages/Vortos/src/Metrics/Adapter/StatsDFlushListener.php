<?php

declare(strict_types=1);

namespace Vortos\Metrics\Adapter;

use Vortos\Http\Contract\TerminableMiddlewareInterface;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Flushes the StatsD send buffer after each HTTP response.
 *
 * In FrankenPHP worker mode the PHP process is long-lived — StatsDMetrics::__destruct()
 * never runs between requests. terminate() is called after the response is sent
 * so metrics are dispatched once per request with minimal syscall overhead.
 *
 * Only registered by MetricsExtension when adapter = StatsD.
 */
final class StatsDFlushListener implements TerminableMiddlewareInterface
{
    public function __construct(private readonly StatsDMetrics $metrics) {}

    public function terminate(Request $request, Response $response): void
    {
        $this->metrics->flush();
    }
}
