<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\SshCa;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Vortos\Deploy\Credential\OidcToken;
use Vortos\Deploy\Credential\SignedSshCertificate;
use Vortos\Deploy\Credential\SshCertificateAuthorityInterface;
use Vortos\Secrets\Value\SecretValue;

final class HttpSshCertificateAuthority implements SshCertificateAuthorityInterface
{
    private const MAX_TTL_SECONDS = 300;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly string $caEndpoint,
    ) {}

    public function sign(string $publicKey, OidcToken $oidcToken, int $ttlSeconds): SignedSshCertificate
    {
        $ttl = min(max($ttlSeconds, 1), self::MAX_TTL_SECONDS);

        $body = json_encode([
            'public_key' => $publicKey,
            'oidc_token' => $oidcToken->rawJwt->reveal(),
            'ttl' => $ttl,
        ], JSON_THROW_ON_ERROR);

        $request = $this->requestFactory->createRequest('POST', $this->caEndpoint)
            ->withHeader('Content-Type', 'application/json');

        $request->getBody()->write($body);

        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf(
                'SSH CA signing request failed with status %d.',
                $response->getStatusCode(),
            ));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new \RuntimeException('SSH CA returned invalid response.');
        }

        $validBefore = (new \DateTimeImmutable())->modify(sprintf('+%d seconds', $ttl));

        return new SignedSshCertificate(
            certBlob: SecretValue::fromString((string) ($result['certificate'] ?? '')),
            validBefore: $validBefore,
            principals: (array) ($result['principals'] ?? [$oidcToken->environment() ?? 'deploy']),
            serial: (string) ($result['serial'] ?? bin2hex(random_bytes(8))),
        );
    }
}
