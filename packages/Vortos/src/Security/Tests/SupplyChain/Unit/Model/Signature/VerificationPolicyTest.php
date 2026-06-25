<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Unit\Model\Signature;

use PHPUnit\Framework\TestCase;
use Vortos\Security\SupplyChain\Model\Signature\VerificationPolicy;

final class VerificationPolicyTest extends TestCase
{
    public function test_keyless_identity_match(): void
    {
        $policy = VerificationPolicy::keyless('https://token.actions.githubusercontent.com', '.*@github\\.com');
        self::assertTrue($policy->isKeyless());
        self::assertTrue($policy->matchesIdentity('https://token.actions.githubusercontent.com', 'user@github.com'));
    }

    public function test_keyless_identity_mismatch_issuer(): void
    {
        $policy = VerificationPolicy::keyless('https://token.actions.githubusercontent.com', '.*@github\\.com');
        self::assertFalse($policy->matchesIdentity('https://other.issuer.com', 'user@github.com'));
    }

    public function test_keyless_identity_mismatch_san(): void
    {
        $policy = VerificationPolicy::keyless('https://token.actions.githubusercontent.com', '^admin@github\\.com$');
        self::assertFalse($policy->matchesIdentity('https://token.actions.githubusercontent.com', 'user@github.com'));
    }

    public function test_public_key_fingerprint_match(): void
    {
        $policy = VerificationPolicy::publicKey('sha256abcdef');
        self::assertFalse($policy->isKeyless());
        self::assertTrue($policy->matchesFingerprint('sha256abcdef'));
    }

    public function test_public_key_fingerprint_mismatch(): void
    {
        $policy = VerificationPolicy::publicKey('sha256abcdef');
        self::assertFalse($policy->matchesFingerprint('sha256xxxxxx'));
    }

    public function test_keyless_rejects_empty_issuer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VerificationPolicy::keyless('', '.*');
    }

    public function test_keyless_rejects_empty_san_regex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VerificationPolicy::keyless('https://issuer.com', '');
    }

    public function test_public_key_rejects_empty_fingerprint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VerificationPolicy::publicKey('');
    }

    public function test_round_trips_keyless_via_array(): void
    {
        $policy = VerificationPolicy::keyless('https://issuer.com', '.*@example\\.com');
        $restored = VerificationPolicy::fromArray($policy->toArray());
        self::assertTrue($restored->isKeyless());
        self::assertSame('https://issuer.com', $restored->issuer);
    }

    public function test_round_trips_public_key_via_array(): void
    {
        $policy = VerificationPolicy::publicKey('fingerprint123');
        $restored = VerificationPolicy::fromArray($policy->toArray());
        self::assertFalse($restored->isKeyless());
        self::assertTrue($restored->matchesFingerprint('fingerprint123'));
    }

    public function test_from_array_rejects_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VerificationPolicy::fromArray([]);
    }
}
