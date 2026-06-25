<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Credential;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Credential\EphemeralKeyPairFactory;

final class EphemeralKeyPairFactoryTest extends TestCase
{
    public function test_generates_valid_keypair(): void
    {
        $factory = new EphemeralKeyPairFactory();
        $keyPair = $factory->generate();

        $this->assertStringStartsWith('ssh-ed25519 ', $keyPair->publicKey);
        $this->assertFalse($keyPair->privateKey->isWiped());
    }

    public function test_generates_unique_keypairs(): void
    {
        $factory = new EphemeralKeyPairFactory();

        $kp1 = $factory->generate();
        $kp2 = $factory->generate();

        $this->assertNotSame($kp1->publicKey, $kp2->publicKey);
    }

    public function test_private_key_is_secret_value(): void
    {
        $factory = new EphemeralKeyPairFactory();
        $keyPair = $factory->generate();

        $this->assertSame('***', (string) $keyPair->privateKey);
        $this->assertNotEmpty($keyPair->privateKey->reveal());
    }

    public function test_private_key_can_be_wiped(): void
    {
        $factory = new EphemeralKeyPairFactory();
        $keyPair = $factory->generate();

        $keyPair->privateKey->wipe();

        $this->assertTrue($keyPair->privateKey->isWiped());
    }

    public function test_public_key_is_decodable(): void
    {
        $factory = new EphemeralKeyPairFactory();
        $keyPair = $factory->generate();

        $parts = explode(' ', $keyPair->publicKey);
        $this->assertCount(2, $parts);
        $this->assertSame('ssh-ed25519', $parts[0]);

        $decoded = base64_decode($parts[1], true);
        $this->assertNotFalse($decoded);
        $this->assertGreaterThan(0, strlen($decoded));
    }

    public function test_no_shell_exec_used(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/Credential/EphemeralKeyPairFactory.php');
        $this->assertStringNotContainsString('ssh-keygen', $source);
        $this->assertStringNotContainsString('exec(', $source);
        $this->assertStringNotContainsString('shell_exec', $source);
        $this->assertStringNotContainsString('proc_open', $source);
    }
}
