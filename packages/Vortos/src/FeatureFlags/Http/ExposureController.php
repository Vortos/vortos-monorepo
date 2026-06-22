<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\FeatureFlags\Exposure\ExposureEvent;
use Vortos\FeatureFlags\Exposure\ExposureIngestService;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;

/**
 * Receives exposure events from client SDKs (wire contract §5, Block 8) — the server side
 * of `@vortos/flags`' `exposureEndpoint`. Accepts a single `{name,variant?,timestamp}` or a
 * batch array of them, with the same `X-Vortos-Flag-Context` header as `GET /api/flags`.
 *
 * Hardening: bounded body + batch size, malformed items skipped, unknown flags dropped by
 * the ingest service (cardinality-DoS guard). Always responds fast (202) — never blocks the
 * SDK on a downstream metrics flush.
 *
 * NOTE(Block 13): authentication here currently relies on whatever the app mounts in front
 * of the flag API; per-environment scoped SDK keys replace that when Block 13 lands.
 */
#[AsController]
#[Route('/api/flags/exposures', name: 'vortos.flags.exposures', methods: ['POST'])]
final class ExposureController
{
    /** Reject obviously oversized bodies before decoding. */
    private const MAX_BODY_BYTES = 256 * 1024;

    /** Cap events processed per request. */
    private const MAX_BATCH = 200;

    public function __construct(
        private readonly ExposureIngestService $ingest,
        private readonly FlagContextResolverInterface $contextResolver,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return new JsonResponse(['error' => 'payload too large'], 413);
        }

        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'invalid json'], 400);
        }

        if (!is_array($decoded)) {
            return new JsonResponse(['error' => 'expected an object or array'], 400);
        }

        // Single object (has a "name") vs. a batch array.
        $items = array_key_exists('name', $decoded) ? [$decoded] : $decoded;

        $events = [];
        foreach ($items as $item) {
            if (count($events) >= self::MAX_BATCH) {
                break;
            }
            if (is_array($item)) {
                $event = ExposureEvent::fromArray($item);
                if ($event !== null) {
                    $events[] = $event;
                }
            }
        }

        $context  = $this->contextResolver->resolve($request);
        $accepted = $this->ingest->ingest($events, $context->cacheKey());

        return new JsonResponse(['accepted' => $accepted], 202);
    }
}
