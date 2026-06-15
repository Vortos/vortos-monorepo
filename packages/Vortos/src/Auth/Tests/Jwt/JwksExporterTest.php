<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\Jwt;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Jwt\Jwks\JwksExporter;
use Vortos\Auth\Jwt\Key\Keyring;
use Vortos\Auth\Jwt\Key\KeyStatus;
use Vortos\Auth\Jwt\Key\SigningKey;

final class JwksExporterTest extends TestCase
{
    public function test_hs256_keyring_is_not_publishable(): void
    {
        $ring = Keyring::fromSecret('secret-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $exporter = new JwksExporter($ring);

        $this->assertFalse($exporter->isSupported());
        $this->assertSame(['keys' => []], $exporter->export());
    }

    public function test_rs256_keyring_exports_every_key_as_jwk(): void
    {
        [$priv1, $pub1] = KeyringTest::rsaKeyPair();
        [$priv2, $pub2] = KeyringTest::rsaKeyPair();

        $ring = new Keyring(
            SigningKey::rs256('k1', $priv1, $pub1, KeyStatus::Retiring),
            SigningKey::rs256('k2', $priv2, $pub2, KeyStatus::Active),
        );
        $exporter = new JwksExporter($ring);

        $this->assertTrue($exporter->isSupported());

        $jwks = $exporter->export();
        $this->assertCount(2, $jwks['keys']);

        $byKid = array_column($jwks['keys'], null, 'kid');
        $this->assertArrayHasKey('k1', $byKid);
        $this->assertArrayHasKey('k2', $byKid);

        foreach ($jwks['keys'] as $jwk) {
            $this->assertSame('RSA', $jwk['kty']);
            $this->assertSame('sig', $jwk['use']);
            $this->assertSame('RS256', $jwk['alg']);
            $this->assertNotEmpty($jwk['n']);
            $this->assertNotEmpty($jwk['e']);
            // base64url — no padding or '+' / '/'.
            $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $jwk['n']);
        }
    }

    public function test_jwks_n_matches_the_public_key_modulus(): void
    {
        [$priv, $pub] = KeyringTest::rsaKeyPair();
        $ring = new Keyring(SigningKey::rs256('k1', $priv, $pub, KeyStatus::Active));

        $jwk = (new JwksExporter($ring))->export()['keys'][0];

        $details = openssl_pkey_get_details(openssl_pkey_get_public($pub));
        $expectedN = rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '=');

        $this->assertSame($expectedN, $jwk['n']);
    }
}
