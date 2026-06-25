<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\GitHubOidc;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Vortos\Deploy\Credential\OidcToken;
use Vortos\Deploy\Credential\OidcTokenSourceInterface;

final class GitHubActionsOidcTokenSource implements OidcTokenSourceInterface
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
    ) {}

    public function requestToken(string $audience): OidcToken
    {
        $requestUrl = getenv('ACTIONS_ID_TOKEN_REQUEST_URL');
        $requestToken = getenv('ACTIONS_ID_TOKEN_REQUEST_TOKEN');

        if ($requestUrl === false || $requestUrl === '' || $requestToken === false || $requestToken === '') {
            throw new \RuntimeException(
                'GitHub Actions OIDC not available: ACTIONS_ID_TOKEN_REQUEST_URL and ACTIONS_ID_TOKEN_REQUEST_TOKEN must be set.',
            );
        }

        $url = $requestUrl . '&audience=' . urlencode($audience);

        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('Authorization', 'bearer ' . $requestToken)
            ->withHeader('Accept', 'application/json');

        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf(
                'GitHub OIDC token request failed with status %d.',
                $response->getStatusCode(),
            ));
        }

        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body) || !isset($body['value']) || !is_string($body['value'])) {
            throw new \RuntimeException('GitHub OIDC response missing "value" field.');
        }

        return OidcToken::fromJwt($body['value']);
    }
}
