<?php

declare(strict_types=1);

namespace Vortos\Push\Crypto;

use Vortos\Push\Support\Base64Url;

/**
 * Encrypts a Web Push payload with the `aes128gcm` content encoding (RFC 8188)
 * and the ECDH key agreement of RFC 8291, using only ext-openssl.
 *
 * The returned string is the complete request body for a push endpoint: an
 * aes128gcm block whose header carries the random salt and the ephemeral server
 * public key, followed by a single AES-128-GCM record.
 *
 * Correctness is pinned by a decrypt round-trip test — do not alter the HKDF
 * info strings or the record delimiter without re-running it.
 */
final class WebPushEncryptor
{
    /** DER SubjectPublicKeyInfo prefix for an uncompressed P-256 point (through the leading 0x04). */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d03010703420004';

    private const RECORD_SIZE = 4096;

    /**
     * @param string $p256dh base64url of the subscription's uncompressed public key (65 bytes)
     * @param string $auth   base64url of the subscription's auth secret (16 bytes)
     * @return string binary aes128gcm request body
     */
    public function encrypt(string $plaintext, string $p256dh, string $auth): string
    {
        $uaPublic   = Base64Url::decode($p256dh);
        $authSecret = Base64Url::decode($auth);

        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04") {
            throw new \RuntimeException('Invalid subscription public key.');
        }

        // 1. Ephemeral server keypair + ECDH shared secret.
        $serverKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        if ($serverKey === false) {
            throw new \RuntimeException('Failed to generate ephemeral EC key.');
        }

        $details      = openssl_pkey_get_details($serverKey);
        $serverPublic = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
                              . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $peerPublic = openssl_pkey_get_public($this->spkiPem($uaPublic));
        if ($peerPublic === false) {
            throw new \RuntimeException('Failed to parse subscription public key.');
        }
        $sharedSecret = openssl_pkey_derive($peerPublic, $serverKey);
        if ($sharedSecret === false) {
            throw new \RuntimeException('ECDH derivation failed.');
        }

        // 2. RFC 8291 §3.4 — combine the ECDH secret with the auth secret.
        $prkKey  = hash_hmac('sha256', $sharedSecret, $authSecret, true);
        $keyInfo = 'WebPush: info' . "\x00" . $uaPublic . $serverPublic;
        $ikm     = substr(hash_hmac('sha256', $keyInfo . "\x01", $prkKey, true), 0, 32);

        // 3. RFC 8188 — derive CEK and nonce from a fresh salt.
        $salt  = random_bytes(16);
        $prk   = hash_hmac('sha256', $ikm, $salt, true);
        $cek   = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);

        // 4. Single, last record: plaintext followed by the 0x02 delimiter.
        $record = $plaintext . "\x02";

        $tag = '';
        $ciphertext = openssl_encrypt($record, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false) {
            throw new \RuntimeException('AES-128-GCM encryption failed.');
        }

        // 5. aes128gcm header: salt(16) | rs(uint32) | idlen(1) | keyid(server public).
        $header = $salt
            . pack('N', self::RECORD_SIZE)
            . chr(strlen($serverPublic))
            . $serverPublic;

        return $header . $ciphertext . $tag;
    }

    private function spkiPem(string $uaPublic): string
    {
        $der = hex2bin(self::P256_SPKI_PREFIX) . substr($uaPublic, 1);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }
}
