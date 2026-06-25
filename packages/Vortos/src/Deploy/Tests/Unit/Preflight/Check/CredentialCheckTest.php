<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight\Check;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Credential\CredentialProviderInterface;
use Vortos\Deploy\Credential\IssuedCredential;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Exception\CredentialNotIssuableException;
use Vortos\Deploy\Preflight\Check\CredentialCheck;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Secrets\Value\SecretValue;

final class CredentialCheckTest extends TestCase
{
    use PreflightTestFactory;

    public function test_healthy_provider_passes(): void
    {
        $finding = (new CredentialCheck($this->credentialRegistry()))->check($this->context());

        $this->assertSame(PreflightStatus::Pass, $finding->status);
        $this->assertSame('credential.issuable', $finding->id);
    }

    public function test_missing_provider_fails(): void
    {
        $finding = (new CredentialCheck($this->credentialRegistry()))
            ->check($this->context($this->definition(credential: 'ghost')));

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('not registered', $finding->summary);
    }

    public function test_not_issuable_fails(): void
    {
        $spy = $this->spyProvider(issuable: false);
        $registry = $this->credentialRegistry(['fake-credential' => $spy]);

        $finding = (new CredentialCheck($registry))->check($this->context());

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertSame(0, $spy->issueCalls, 'preflight must never call issue()');
    }

    public function test_preflight_never_mints(): void
    {
        $spy = $this->spyProvider(issuable: true);
        $registry = $this->credentialRegistry(['fake-credential' => $spy]);

        (new CredentialCheck($registry))->check($this->context());

        $this->assertSame(1, $spy->assertCalls);
        $this->assertSame(0, $spy->issueCalls, 'preflight must never mint a credential');
    }

    private function spyProvider(bool $issuable): CredentialProviderInterface
    {
        return new class($issuable) implements CredentialProviderInterface {
            public int $issueCalls = 0;
            public int $assertCalls = 0;

            public function __construct(private readonly bool $issuable) {}

            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([]);
            }

            public function assertIssuable(EnvironmentName $env): void
            {
                $this->assertCalls++;
                if (!$this->issuable) {
                    throw CredentialNotIssuableException::forProvider('spy', 'forced not issuable');
                }
            }

            public function issue(EnvironmentName $env): IssuedCredential
            {
                $this->issueCalls++;

                return new IssuedCredential(
                    type: 'ssh-key',
                    material: SecretValue::fromString('x'),
                    expiresAt: new \DateTimeImmutable('+1 hour'),
                    issuedFor: $env->value,
                );
            }
        };
    }
}
