<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\Credential\OidcToken;
use Vortos\Deploy\Credential\OidcTokenSourceInterface;
use Vortos\Secrets\Value\SecretValue;

final class FakeOidcTokenSource implements OidcTokenSourceInterface
{
    private ?OidcToken $nextToken = null;
    private bool $shouldFail = false;

    public function requestToken(string $audience): OidcToken
    {
        if ($this->shouldFail) {
            throw new \RuntimeException('OIDC token request failed (fake).');
        }

        if ($this->nextToken !== null) {
            return $this->nextToken;
        }

        return new OidcToken(
            rawJwt: SecretValue::fromString('fake.jwt.token'),
            claims: [
                'sub' => 'repo:org/app:environment:staging',
                'repository' => 'org/app',
                'ref' => 'refs/heads/main',
                'environment' => 'staging',
                'aud' => $audience,
            ],
            expiresAt: new \DateTimeImmutable('+10 minutes'),
        );
    }

    public function willReturn(OidcToken $token): void
    {
        $this->nextToken = $token;
    }

    public function willFail(): void
    {
        $this->shouldFail = true;
    }
}
