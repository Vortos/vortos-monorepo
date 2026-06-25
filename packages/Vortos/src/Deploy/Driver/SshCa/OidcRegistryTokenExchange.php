<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\SshCa;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Vortos\Deploy\Credential\OidcToken;
use Vortos\Deploy\Credential\RegistryTokenExchangeInterface;
use Vortos\Secrets\Value\SecretValue;

final class OidcRegistryTokenExchange implements RegistryTokenExchangeInterface
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly string $exchangeEndpoint,
    ) {}

    public function exchange(OidcToken $oidcToken): SecretValue
    {
        $body = json_encode([
            'oidc_token' => $oidcToken->rawJwt->reveal(),
        ], JSON_THROW_ON_ERROR);

        $request = $this->requestFactory->createRequest('POST', $this->exchangeEndpoint)
            ->withHeader('Content-Type', 'application/json');

        $request->getBody()->write($body);

        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf(
                'Registry token exchange failed with status %d.',
                $response->getStatusCode(),
            ));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result) || !isset($result['token']) || !is_string($result['token'])) {
            throw new \RuntimeException('Registry token exchange returned invalid response.');
        }

        return SecretValue::fromString($result['token']);
    }
}
