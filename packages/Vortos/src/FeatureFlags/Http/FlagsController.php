<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http;

use Vortos\Http\JsonResponse;
use Vortos\Http\Request;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\FeatureFlags\FlagRegistryInterface;
use Vortos\Http\Attribute\AsController;

#[AsController]
#[Route('/api/flags', name: 'vortos.flags', methods: ['GET'])]
final class FlagsController
{
    public function __construct(
        private readonly FlagRegistryInterface $registry,
        private readonly FlagContextResolverInterface $contextResolver,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = $this->contextResolver->resolve($request);
        $result  = $this->registry->allForContext($context);
        $etag    = '"' . $result['version'] . '"';

        // Block 16 — ETag / 304: if the client's flag config is current, skip serialization.
        $ifNoneMatch = $request->headers->get('If-None-Match');
        if ($ifNoneMatch !== null && $this->etagMatches($ifNoneMatch, $etag)) {
            return new JsonResponse(null, 304, ['ETag' => $etag]);
        }

        $response = new JsonResponse($result);
        $response->headers->set('ETag', $etag);

        return $response;
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
