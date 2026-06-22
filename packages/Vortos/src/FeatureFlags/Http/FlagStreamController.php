<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http;

use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\FeatureFlags\Delivery\FlagChangeNotifierInterface;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagRegistryInterface;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Request;

/**
 * SSE endpoint for live flag-change pushes (Block 16).
 *
 * Sends a `flag-change` event carrying the new version (NOT the payload — the client
 * re-fetches `/api/flags` with its own context, keeping per-context correctness and
 * never leaking other tenants' state). Heartbeat every ~25s defeats idle proxies.
 *
 * Connection limit: the middleware layer / Caddy should cap concurrent SSE connections
 * per SDK key to prevent slow-loris / connection exhaustion.
 */
#[AsController]
#[Route('/api/flags/stream', name: 'vortos.flags.stream', methods: ['GET'])]
final class FlagStreamController
{
    private const HEARTBEAT_INTERVAL = 25.0;
    private const MAX_CONNECTION_SECONDS = 300.0;

    public function __construct(
        private readonly FlagRegistryInterface $registry,
        private readonly FlagChangeNotifierInterface $notifier,
        private readonly FlagScopeContext $scopeContext,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $response = new StreamedResponse(function () {
            $env         = $this->scopeContext->environment();
            $result      = $this->registry->allForContext(new FlagContext());
            $lastVersion = $result['version'];
            $start       = microtime(true);

            // Send initial version
            $this->sendEvent('connected', json_encode(['version' => $lastVersion]));

            while ((microtime(true) - $start) < self::MAX_CONNECTION_SECONDS) {
                if (connection_aborted()) {
                    break;
                }

                $newVersion = $this->notifier->waitForChange($env, $lastVersion, self::HEARTBEAT_INTERVAL);

                if ($newVersion !== null) {
                    $lastVersion = $newVersion;
                    $this->sendEvent('flag-change', json_encode(['version' => $newVersion]));
                } else {
                    // Heartbeat comment to keep connection alive
                    echo ": heartbeat\n\n";
                    $this->flush();
                }
            }

            $this->sendEvent('timeout', json_encode(['reason' => 'max_connection_time']));
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        $retryMs = 3000;
        $response->headers->set('X-SSE-Retry', (string) $retryMs);

        return $response;
    }

    private function sendEvent(string $event, string $data): void
    {
        echo "event: {$event}\n";
        echo "data: {$data}\n\n";
        $this->flush();
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
