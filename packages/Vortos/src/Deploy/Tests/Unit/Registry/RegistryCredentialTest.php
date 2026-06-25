<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Registry\BasicAuthCredential;
use Vortos\Deploy\Registry\GcpServiceAccountCredential;
use Vortos\Deploy\Registry\PatTokenCredential;
use Vortos\Deploy\Registry\RegistryCredential;
use Vortos\Secrets\Value\SecretValue;

final class RegistryCredentialTest extends TestCase
{
    public function test_ghcr_credential_is_registry_credential(): void
    {
        $cred = new PatTokenCredential('actor', SecretValue::fromString('token'));
        $this->assertInstanceOf(RegistryCredential::class, $cred);
    }

    public function test_basic_auth_credential_is_registry_credential(): void
    {
        $cred = new BasicAuthCredential('user', SecretValue::fromString('pass'));
        $this->assertInstanceOf(RegistryCredential::class, $cred);
    }

    public function test_gcp_credential_is_registry_credential(): void
    {
        $cred = new GcpServiceAccountCredential('host', SecretValue::fromString('{}'));
        $this->assertInstanceOf(RegistryCredential::class, $cred);
    }

    public function test_ghcr_credential_rejects_empty_username(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PatTokenCredential('', SecretValue::fromString('token'));
    }

    public function test_basic_auth_credential_rejects_empty_username(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BasicAuthCredential('', SecretValue::fromString('pass'));
    }

    public function test_gcp_credential_rejects_empty_host(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GcpServiceAccountCredential('', SecretValue::fromString('{}'));
    }

    public function test_ghcr_token_is_secret_value(): void
    {
        $secret = SecretValue::fromString('ghp_secret');
        $cred = new PatTokenCredential('actor', $secret);

        $this->assertSame('***', (string) $cred->token);
        $this->assertSame('ghp_secret', $cred->token->reveal());
    }

    public function test_basic_auth_password_is_secret_value(): void
    {
        $secret = SecretValue::fromString('dckr_pat_secret');
        $cred = new BasicAuthCredential('user', $secret);

        $this->assertSame('***', (string) $cred->password);
    }

    public function test_gcp_json_is_secret_value(): void
    {
        $secret = SecretValue::fromString('{"private_key":"..."}');
        $cred = new GcpServiceAccountCredential('host', $secret);

        $this->assertSame('***', (string) $cred->serviceAccountJson);
    }

    public function test_credentials_are_distinct_types(): void
    {
        $ghcr = new PatTokenCredential('a', SecretValue::fromString('t'));
        $basic = new BasicAuthCredential('u', SecretValue::fromString('p'));
        $gcp = new GcpServiceAccountCredential('h', SecretValue::fromString('{}'));

        $this->assertNotInstanceOf(BasicAuthCredential::class, $ghcr);
        $this->assertNotInstanceOf(GcpServiceAccountCredential::class, $basic);
        $this->assertNotInstanceOf(PatTokenCredential::class, $gcp);
    }
}
