<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\Credential\OidcToken;
use Vortos\Deploy\Credential\SignedSshCertificate;
use Vortos\Deploy\Credential\SshCertificateAuthorityInterface;
use Vortos\Secrets\Value\SecretValue;

final class FakeSshCertificateAuthority implements SshCertificateAuthorityInterface
{
    private const MAX_TTL = 300;
    private bool $shouldFail = false;

    /** @var array<string, list<string>> environment → allowed principals */
    private array $principalBindings = [];

    public function sign(string $publicKey, OidcToken $oidcToken, int $ttlSeconds): SignedSshCertificate
    {
        if ($this->shouldFail) {
            throw new \RuntimeException('CA signing failed (fake): bad/expired OIDC token.');
        }

        $ttl = min(max($ttlSeconds, 1), self::MAX_TTL);
        $environment = $oidcToken->environment() ?? 'default';
        $principals = $this->principalBindings[$environment] ?? [$environment];

        return new SignedSshCertificate(
            certBlob: SecretValue::fromString('fake-cert-' . $environment),
            validBefore: (new \DateTimeImmutable())->modify(sprintf('+%d seconds', $ttl)),
            principals: $principals,
            serial: bin2hex(random_bytes(8)),
        );
    }

    public function willFail(): void
    {
        $this->shouldFail = true;
    }

    /** @param list<string> $principals */
    public function bindPrincipals(string $environment, array $principals): void
    {
        $this->principalBindings[$environment] = $principals;
    }
}
