<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagRegistryInterface;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;

/**
 * Context-free bootstrap snapshot for cold-start clients (Block 16).
 *
 * Returns the flag state evaluated against an **empty context** — no targeting rules,
 * no PII, no segment definitions leak. CDN-cacheable via strong ETag +
 * Cache-Control: public, max-age, stale-while-revalidate.
 *
 * A kill-switch flip will be picked up at the next revalidation — `max-age` is kept
 * short (60s default) so a kill-switch is never stuck behind a CDN cache for long.
 */
#[AsController]
#[Route('/api/flags/bootstrap', name: 'vortos.flags.bootstrap', methods: ['GET'])]
final class FlagBootstrapController
{
    public function __construct(
        private readonly FlagRegistryInterface $registry,
        private readonly int $maxAgeSeconds = 60,
        private readonly int $staleWhileRevalidateSeconds = 30,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $result  = $this->registry->allForContext(new FlagContext());
        $version = $result['version'];
        $etag    = '"' . $version . '"';

        // ETag / 304 support
        $ifNoneMatch = $request->headers->get('If-None-Match');
        if ($ifNoneMatch !== null && $this->etagMatches($ifNoneMatch, $etag)) {
            return new JsonResponse(null, 304, [
                'ETag'          => $etag,
                'Cache-Control' => $this->cacheControl(),
            ]);
        }

        // Sanitize: only emit flags/variants/payloads/version — never rules/segments/PII
        $response = new JsonResponse([
            'flags'    => $result['flags'],
            'variants' => $result['variants'],
            'payloads' => $result['payloads'],
            'version'  => $version,
        ]);

        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', $this->cacheControl());
        $response->headers->set('Vary', 'Accept-Encoding');

        return $response;
    }

    private function cacheControl(): string
    {
        return sprintf(
            'public, max-age=%d, stale-while-revalidate=%d',
            $this->maxAgeSeconds,
            $this->staleWhileRevalidateSeconds,
        );
    }

    private function etagMatches(string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === '*') {
            return true;
        }

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            if (trim($candidate) === $etag) {
                return true;
            }
        }

        return false;
    }
}
