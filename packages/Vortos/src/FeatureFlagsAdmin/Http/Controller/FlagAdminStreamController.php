<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Http\Controller;

use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\FeatureFlags\Delivery\FlagChangeNotifierInterface;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Request;

/**
 * Admin-authed SSE endpoint that proxies flag-change notifications to the dashboard.
 *
 * Protected by AdminAuthMiddleware (prefix /admin/flags). Sends a bare `flag-change`
 * event so the dashboard JS can trigger an HTMX reload of the flag table — the event
 * carries no payload (the table re-fetches with its own context).
 */
#[AsController]
#[Route('/admin/flags/stream', name: 'vortos.admin.flags.stream', methods: ['GET'])]
final class FlagAdminStreamController
{
    private const HEARTBEAT_INTERVAL   = 25.0;
    private const MAX_CONNECTION_SECS  = 300.0;

    public function __construct(
        private readonly FlagChangeNotifierInterface $notifier,
        private readonly FlagScopeContext $scopeContext,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $env = (string) ($request->query->get('env') ?? FlagScopeContext::ENV_PRODUCTION);
        $this->scopeContext->withEnvironment($env);

        $response = new StreamedResponse(function () use ($env): void {
            $start       = microtime(true);
            $lastVersion = '';

            echo "event: connected\ndata: {}\n\n";
            $this->flush();

            while ((microtime(true) - $start) < self::MAX_CONNECTION_SECS) {
                if (connection_aborted()) {
                    break;
                }

                $newVersion = $this->notifier->waitForChange($env, $lastVersion, self::HEARTBEAT_INTERVAL);

                if ($newVersion !== null && $newVersion !== $lastVersion) {
                    $lastVersion = $newVersion;
                    echo "event: flag-change\ndata: {}\n\n";
                    $this->flush();
                } else {
                    echo ": heartbeat\n\n";
                    $this->flush();
                }
            }

            echo "event: timeout\ndata: {}\n\n";
            $this->flush();
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
