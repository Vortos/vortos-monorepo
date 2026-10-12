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

    /**
     * RC-7: a node that only verifies holds the public half. It must verify what the signer issued,
     * and refuse to sign rather than produce anything.
     */
    public function test_a_verify_only_key_verifies_the_signers_tokens_and_cannot_sign(): void
    {
        [$priv, $pub] = self::rsaKeyPair();
        $signer = SigningKey::rs256('2026-10', $priv, $pub);
        $verifier = new Keyring(SigningKey::rs256VerifyOnly('2026-10', $pub));

        $token = \Firebase\JWT\JWT::encode(['sub' => 'u1'], $signer->signingMaterial(), 'RS256', '2026-10');
        $claims = \Firebase\JWT\JWT::decode($token, $verifier->verificationKeys());

        $this->assertSame('u1', $claims->sub);
        $this->assertTrue($signer->canSign());
        $this->assertFalse($verifier->activeSigningKey()->canSign());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("'2026-10' is verify-only");
        $verifier->activeSigningKey()->signingMaterial();
    }

    public function test_a_verify_only_key_needs_its_public_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SigningKey::rs256VerifyOnly('k', '');
    }

    /** The prod HTTP container is dumped, so a verify-only key must survive the dumpable definition. */
    public function test_a_verify_only_key_survives_the_dumped_keyring_definition(): void
    {
        [, $pub] = self::rsaKeyPair();
        $config = (new \Vortos\Auth\DependencyInjection\VortosAuthConfig())->rs256VerifyOnly('k', $pub);

        $definition = $config->keyringDefinition()->getArgument(0);

        $this->assertSame([SigningKey::class, 'rs256VerifyOnly'], $definition->getFactory());
        $this->assertSame(['k', $pub, KeyStatus::Active], $definition->getArguments());
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
