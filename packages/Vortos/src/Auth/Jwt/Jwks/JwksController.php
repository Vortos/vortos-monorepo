<?php

declare(strict_types=1);

namespace Vortos\Auth\Jwt\Jwks;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\JsonResponse;
use Vortos\Http\Response;

/**
 * Publishes the app's public signing keys as a JWKS document at
 * `/.well-known/jwks.json`, so downstream services and API gateways can verify
 * the JWTs this app issues without sharing a secret.
 *
 * Only registered when JWKS is enabled (->jwks(true) in config/auth.php) and
 * only meaningful for RS256 keyrings — an HS256 keyring has no publishable
 * public key and returns 404.
 */
#[AsController]
final class JwksController
{
    public function __construct(private readonly JwksExporter $exporter) {}

    #[Route('/.well-known/jwks.json', name: 'vortos.auth.jwks', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        if (!$this->exporter->isSupported()) {
            return new JsonResponse(
                ['error' => 'JWKS is only available for RS256 signing keys.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse(
            $this->exporter->export(),
            Response::HTTP_OK,
            ['Cache-Control' => 'public, max-age=3600'],
        );
    }
}
