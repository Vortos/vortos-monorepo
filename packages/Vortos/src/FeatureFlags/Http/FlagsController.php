<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\FeatureFlags\FlagRegistry;
use Vortos\Http\Attribute\ApiController;

#[ApiController]
#[Route('/api/flags', name: 'vortos.flags', methods: ['GET'])]
final class FlagsController
{
    public function __construct(
        private readonly FlagRegistry $registry,
        private readonly FlagContextResolverInterface $contextResolver,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = $this->contextResolver->resolve($request);
        $result  = $this->registry->allForContext($context);

        return new JsonResponse($result);
    }
}
