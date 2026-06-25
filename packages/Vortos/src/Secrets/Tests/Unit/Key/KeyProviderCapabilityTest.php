<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Key;

use PHPUnit\Framework\TestCase;
use Vortos\OpsKit\Driver\Capability\CapabilityKey;
use Vortos\Secrets\Key\KeyProviderCapability;

final class KeyProviderCapabilityTest extends TestCase
{
    public function testImplementsCapabilityKey(): void
    {
        foreach (KeyProviderCapability::cases() as $cap) {
            self::assertInstanceOf(CapabilityKey::class, $cap);
        }
    }

    public function testKeyReturnsValue(): void
    {
        self::assertSame('off_host_key', KeyProviderCapability::OffHostKey->key());
        self::assertSame('wrap', KeyProviderCapability::Wrap->key());
        self::assertSame('unwrap', KeyProviderCapability::Unwrap->key());
        self::assertSame('envelope_encryption', KeyProviderCapability::EnvelopeEncryption->key());
    }

    public function testCaseCount(): void
    {
        self::assertCount(4, KeyProviderCapability::cases());
    }
}
