<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Credential;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Credential\CredentialLease;
use Vortos\Deploy\Credential\CredentialUse;
use Vortos\Deploy\Credential\IssuedCredential;
use Vortos\Secrets\Value\SecretValue;

final class CredentialLeaseTest extends TestCase
{
    public function test_use_returns_callback_result(): void
    {
        $lease = $this->makeLease();

        $result = $lease->use(fn (CredentialUse $use) => 'done');

        $this->assertSame('done', $result);
    }

    public function test_use_wipes_secrets_on_normal_return(): void
    {
        $secret = SecretValue::fromString('key-material');
        $lease = $this->makeLease(secrets: [$secret]);

        $lease->use(fn () => null);

        $this->assertTrue($secret->isWiped());
        $this->assertTrue($lease->isClosed());
    }

    public function test_use_wipes_secrets_on_thrown_exception(): void
    {
        $secret = SecretValue::fromString('key-material');
        $lease = $this->makeLease(secrets: [$secret]);

        try {
            $lease->use(function () {
                throw new \RuntimeException('deploy step failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertTrue($secret->isWiped(), 'Secret must be wiped even when the callback throws.');
        $this->assertTrue($lease->isClosed());
    }

    public function test_wipe_is_idempotent(): void
    {
        $secret = SecretValue::fromString('material');
        $lease = $this->makeLease(secrets: [$secret]);

        $lease->wipe();
        $lease->wipe(); // should not throw

        $this->assertTrue($lease->isClosed());
    }

    public function test_use_after_close_throws(): void
    {
        $lease = $this->makeLease();
        $lease->wipe();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already closed');

        $lease->use(fn () => null);
    }

    public function test_ephemeral_paths_are_unlinked_on_wipe(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'cred_test_');
        file_put_contents($tmpFile, 'ephemeral-key');

        $lease = $this->makeLease(ephemeralPaths: [$tmpFile]);

        $lease->wipe();

        $this->assertFileDoesNotExist($tmpFile);
    }

    public function test_ephemeral_paths_unlinked_even_on_exception(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'cred_test_');
        file_put_contents($tmpFile, 'ephemeral-key');

        $lease = $this->makeLease(ephemeralPaths: [$tmpFile]);

        try {
            $lease->use(function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertFileDoesNotExist($tmpFile);
    }

    public function test_multiple_secrets_all_wiped(): void
    {
        $s1 = SecretValue::fromString('secret-1');
        $s2 = SecretValue::fromString('secret-2');
        $s3 = SecretValue::fromString('secret-3');

        $lease = $this->makeLease(secrets: [$s1, $s2, $s3]);

        $lease->use(fn () => null);

        $this->assertTrue($s1->isWiped());
        $this->assertTrue($s2->isWiped());
        $this->assertTrue($s3->isWiped());
    }

    public function test_credential_use_exposes_identity_path(): void
    {
        $use = new CredentialUse(
            credential: new IssuedCredential(
                type: 'ssh-cert',
                material: SecretValue::fromString('cert'),
                expiresAt: new \DateTimeImmutable('+5 minutes'),
            ),
            identityPath: '/dev/shm/deploy-key',
            registryToken: null,
        );

        $this->assertSame('/dev/shm/deploy-key', $use->identityPath());
        $this->assertSame('ssh-cert', $use->type());
        $this->assertNull($use->registryToken());
    }

    public function test_credential_use_exposes_registry_token(): void
    {
        $token = SecretValue::fromString('registry-token');
        $use = new CredentialUse(
            credential: new IssuedCredential(
                type: 'pull-agent',
                material: SecretValue::fromString('no-material'),
                expiresAt: new \DateTimeImmutable('+30 minutes'),
            ),
            identityPath: null,
            registryToken: $token,
        );

        $this->assertSame('registry-token', $use->registryToken()->reveal());
    }

    public function test_credential_use_is_expired(): void
    {
        $use = new CredentialUse(
            credential: new IssuedCredential(
                type: 'ssh-key',
                material: SecretValue::fromString('key'),
                expiresAt: new \DateTimeImmutable('-1 minute'),
            ),
            identityPath: null,
            registryToken: null,
        );

        $this->assertTrue($use->isExpired(new \DateTimeImmutable()));
    }

    public function test_credential_use_not_expired(): void
    {
        $use = new CredentialUse(
            credential: new IssuedCredential(
                type: 'ssh-key',
                material: SecretValue::fromString('key'),
                expiresAt: new \DateTimeImmutable('+10 minutes'),
            ),
            identityPath: null,
            registryToken: null,
        );

        $this->assertFalse($use->isExpired(new \DateTimeImmutable()));
    }

    public function test_nonexistent_ephemeral_path_does_not_error(): void
    {
        $lease = $this->makeLease(ephemeralPaths: ['/nonexistent/path/to/key']);

        $lease->wipe(); // should not throw

        $this->assertTrue($lease->isClosed());
    }

    /**
     * @param list<SecretValue> $secrets
     * @param list<string>      $ephemeralPaths
     */
    private function makeLease(array $secrets = [], array $ephemeralPaths = []): CredentialLease
    {
        $use = new CredentialUse(
            credential: new IssuedCredential(
                type: 'ssh-key',
                material: SecretValue::fromString('test-material'),
                expiresAt: new \DateTimeImmutable('+1 hour'),
            ),
            identityPath: null,
            registryToken: null,
        );

        return new CredentialLease($use, $secrets, $ephemeralPaths);
    }
}
