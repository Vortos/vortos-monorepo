<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Vortos\OpsKit\Driver\Capability\CapabilityKey;
use Vortos\Secrets\Provider\SecretsCapability;

final class SecretsCapabilityTest extends TestCase
{
    public function testImplementsCapabilityKey(): void
    {
        foreach (SecretsCapability::cases() as $cap) {
            self::assertInstanceOf(CapabilityKey::class, $cap);
        }
    }

    public function testKeyReturnsValue(): void
    {
        self::assertSame('versioning', SecretsCapability::Versioning->key());
        self::assertSame('rotation', SecretsCapability::Rotation->key());
        self::assertSame('two_phase_rotation', SecretsCapability::TwoPhaseRotation->key());
        self::assertSame('off_host_key', SecretsCapability::OffHostKey->key());
        self::assertSame('put', SecretsCapability::Put->key());
        self::assertSame('list', SecretsCapability::ListKeys->key());
        self::assertSame('in_memory_only', SecretsCapability::InMemoryOnly->key());
    }

    public function testCaseCount(): void
    {
        self::assertCount(7, SecretsCapability::cases());
    }
}
