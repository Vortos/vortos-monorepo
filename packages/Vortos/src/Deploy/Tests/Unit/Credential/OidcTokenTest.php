<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Credential;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Credential\OidcToken;
use Vortos\Secrets\Value\SecretValue;

final class OidcTokenTest extends TestCase
{
    public function test_from_jwt_parses_claims(): void
    {
        $jwt = $this->makeJwt([
            'sub' => 'repo:org/app:environment:staging',
            'repository' => 'org/app',
            'ref' => 'refs/heads/main',
            'environment' => 'staging',
            'aud' => 'deploy',
            'exp' => time() + 600,
        ]);

        $token = OidcToken::fromJwt($jwt);

        $this->assertSame('repo:org/app:environment:staging', $token->subject());
        $this->assertSame('org/app', $token->repository());
        $this->assertSame('refs/heads/main', $token->ref());
        $this->assertSame('staging', $token->environment());
    }

    public function test_from_jwt_raw_is_secret_value(): void
    {
        $jwt = $this->makeJwt(['sub' => 'test', 'exp' => time() + 600]);
        $token = OidcToken::fromJwt($jwt);

        $this->assertSame('***', (string) $token->rawJwt);
        $this->assertSame($jwt, $token->rawJwt->reveal());
    }

    public function test_expired_token(): void
    {
        $token = new OidcToken(
            rawJwt: SecretValue::fromString('jwt'),
            claims: [],
            expiresAt: new \DateTimeImmutable('-1 minute'),
        );

        $this->assertTrue($token->isExpired(new \DateTimeImmutable()));
    }

    public function test_valid_token_not_expired(): void
    {
        $token = new OidcToken(
            rawJwt: SecretValue::fromString('jwt'),
            claims: [],
            expiresAt: new \DateTimeImmutable('+10 minutes'),
        );

        $this->assertFalse($token->isExpired(new \DateTimeImmutable()));
    }

    public function test_from_jwt_rejects_malformed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expected 3 parts');

        OidcToken::fromJwt('not-a-jwt');
    }

    public function test_from_jwt_rejects_invalid_payload(): void
    {
        $header = base64_encode('{}');
        $payload = base64_encode('not-json');
        $sig = base64_encode('sig');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a JSON object');

        OidcToken::fromJwt("{$header}.{$payload}.{$sig}");
    }

    public function test_missing_claim_returns_null(): void
    {
        $token = new OidcToken(
            rawJwt: SecretValue::fromString('jwt'),
            claims: ['sub' => 'test'],
            expiresAt: new \DateTimeImmutable('+1 hour'),
        );

        $this->assertNull($token->environment());
        $this->assertNull($token->repository());
        $this->assertNull($token->ref());
    }

    public function test_from_jwt_handles_missing_exp(): void
    {
        $jwt = $this->makeJwt(['sub' => 'test']);

        $token = OidcToken::fromJwt($jwt);

        $this->assertFalse($token->isExpired(new \DateTimeImmutable()));
    }

    private function makeJwt(array $payload): string
    {
        $header = rtrim(strtr(base64_encode('{"alg":"RS256","typ":"JWT"}'), '+/', '-_'), '=');
        $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode('fake-signature'), '+/', '-_'), '=');

        return "{$header}.{$body}.{$sig}";
    }
}
