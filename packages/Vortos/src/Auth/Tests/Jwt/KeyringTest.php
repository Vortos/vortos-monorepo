<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\Jwt;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Jwt\Key\Keyring;
use Vortos\Auth\Jwt\Key\KeyStatus;
use Vortos\Auth\Jwt\Key\SigningKey;

final class KeyringTest extends TestCase
{
    private const SECRET_A = 'secret-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SECRET_B = 'secret-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function test_requires_at_least_one_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Keyring();
    }

    public function test_requires_exactly_one_active_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Keyring(
            SigningKey::hs256('a', self::SECRET_A, KeyStatus::Active),
            SigningKey::hs256('b', self::SECRET_B, KeyStatus::Active),
        );
    }

    public function test_rejects_zero_active_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Keyring(SigningKey::hs256('a', self::SECRET_A, KeyStatus::Retiring));
    }

    public function test_rejects_duplicate_kids(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Keyring(
            SigningKey::hs256('same', self::SECRET_A, KeyStatus::Active),
            SigningKey::hs256('same', self::SECRET_B, KeyStatus::Next),
        );
    }

    public function test_rejects_mixed_algorithms(): void
    {
        [$priv, $pub] = self::rsaKeyPair();

        $this->expectException(\InvalidArgumentException::class);
        new Keyring(
            SigningKey::hs256('a', self::SECRET_A, KeyStatus::Active),
            SigningKey::rs256('b', $priv, $pub, KeyStatus::Next),
        );
    }

    public function test_active_signing_key_is_the_active_one(): void
    {
        $ring = new Keyring(
            SigningKey::hs256('old', self::SECRET_A, KeyStatus::Retiring),
            SigningKey::hs256('new', self::SECRET_B, KeyStatus::Active),
        );

        $this->assertSame('new', $ring->activeSigningKey()->kid);
    }

    public function test_verification_keys_includes_every_kid(): void
    {
        $ring = new Keyring(
            SigningKey::hs256('old', self::SECRET_A, KeyStatus::Retiring),
            SigningKey::hs256('new', self::SECRET_B, KeyStatus::Active),
        );

        $keys = $ring->verificationKeys();

        $this->assertArrayHasKey('old', $keys);
        $this->assertArrayHasKey('new', $keys);
        $this->assertCount(2, $keys);
    }

    public function test_from_secret_builds_single_active_hs256_ring(): void
    {
        $ring = Keyring::fromSecret(self::SECRET_A);

        $this->assertSame('default', $ring->activeSigningKey()->kid);
        $this->assertSame('HS256', $ring->algorithm());
        $this->assertFalse($ring->isRsa());
    }

    public function test_short_secret_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SigningKey::hs256('a', 'too-short');
    }

    /**
     * @return array{0: string, 1: string} [privatePem, publicPem]
     */
    public static function rsaKeyPair(): array
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $priv);
        $pub = openssl_pkey_get_details($res)['key'];

        return [$priv, $pub];
    }
}
