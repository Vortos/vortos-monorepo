<?php

declare(strict_types=1);

namespace Vortos\Push\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Push\Crypto\WebPushEncryptor;
use Vortos\Push\Support\Base64Url;

/**
 * Proves the aes128gcm encryptor is interoperable by doing what a browser does:
 * generate a subscription keypair, encrypt to it, then DECRYPT with the
 * subscription private key and assert the plaintext round-trips. If the HKDF
 * derivation, the header layout or the GCM record framing were wrong, decryption
 * would fail or return garbage.
 */
final class WebPushEncryptorTest extends TestCase
{
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d03010703420004';

    public function testPayloadRoundTripsThroughBrowserSideDecryption(): void
    {
        // A browser's subscription keypair.
        $uaKey     = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $uaDetails = openssl_pkey_get_details($uaKey);
        $uaPublic  = "\x04"
            . str_pad($uaDetails['ec']['x'], 32, "\0", STR_PAD_LEFT)
            . str_pad($uaDetails['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $authSecret = random_bytes(16);

        $p256dh = Base64Url::encode($uaPublic);
        $auth   = Base64Url::encode($authSecret);

        $plaintext = json_encode(['title' => 'Hello', 'body' => 'Round trip', 'n' => 42]);

        $body = (new WebPushEncryptor())->encrypt($plaintext, $p256dh, $auth);

        $decrypted = $this->decrypt($body, $uaKey, $uaPublic, $authSecret);

        self::assertSame($plaintext, $decrypted);
    }

    public function testRejectsMalformedSubscriptionKey(): void
    {
        $this->expectException(\RuntimeException::class);
        (new WebPushEncryptor())->encrypt('x', Base64Url::encode('too-short'), Base64Url::encode(random_bytes(16)));
    }

    /**
     * Reverses RFC 8291 + RFC 8188 exactly as a browser push subscription would.
     */
    private function decrypt(string $body, \OpenSSLAsymmetricKey $uaPrivate, string $uaPublic, string $authSecret): string
    {
        $salt        = substr($body, 0, 16);
        $idlen       = ord($body[20]);
        $serverPublic = substr($body, 21, $idlen);
        $ciphertext  = substr($body, 21 + $idlen);

        $serverKey    = openssl_pkey_get_public($this->spkiPem($serverPublic));
        $sharedSecret = openssl_pkey_derive($serverKey, $uaPrivate);

        $prkKey  = hash_hmac('sha256', $sharedSecret, $authSecret, true);
        $keyInfo = 'WebPush: info' . "\x00" . $uaPublic . $serverPublic;
        $ikm     = substr(hash_hmac('sha256', $keyInfo . "\x01", $prkKey, true), 0, 32);

        $prk   = hash_hmac('sha256', $ikm, $salt, true);
        $cek   = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);

        $tag        = substr($ciphertext, -16);
        $ct         = substr($ciphertext, 0, -16);
        $record     = openssl_decrypt($ct, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '');

        self::assertNotFalse($record, 'GCM decryption failed');

        // Strip the RFC 8188 last-record delimiter (0x02) and any zero padding.
        return rtrim($record, "\x00") === $record
            ? substr($record, 0, -1)
            : substr(rtrim($record, "\x00"), 0, -1);
    }

    private function spkiPem(string $uaPublic): string
    {
        $der = hex2bin(self::P256_SPKI_PREFIX) . substr($uaPublic, 1);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
