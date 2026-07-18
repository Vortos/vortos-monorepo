<?php

declare(strict_types=1);

namespace Vortos\Push\Tests;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use Vortos\Push\Config\VapidKeys;
use Vortos\Push\Support\Base64Url;
use Vortos\Push\Vapid\VapidHeaderFactory;

final class VapidHeaderFactoryTest extends TestCase
{
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d03010703420004';

    public function testProducesAVerifiableVapidHeaderScopedToTheEndpointOrigin(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $privatePem);
        $details   = openssl_pkey_get_details($key);
        $publicRaw = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $publicB64 = Base64Url::encode($publicRaw);

        $keys    = new VapidKeys($publicB64, (string) $privatePem, 'mailto:ops@example.com');
        $factory = new VapidHeaderFactory($keys);

        $header = $factory->authorizationHeader('https://fcm.googleapis.com/fcm/send/abc123');

        self::assertStringStartsWith('vapid t=', $header);
        self::assertMatchesRegularExpression('/vapid t=([^,]+), k=(.+)$/', $header);

        preg_match('/vapid t=([^,]+), k=(.+)$/', $header, $m);
        [$jwt, $k] = [$m[1], $m[2]];
        self::assertSame($publicB64, $k);

        // The JWT verifies against the public key, and is scoped to the endpoint origin.
        $decoded = JWT::decode($jwt, new Key($this->spkiPem($publicRaw), 'ES256'));
        self::assertSame('https://fcm.googleapis.com', $decoded->aud);
        self::assertSame('mailto:ops@example.com', $decoded->sub);
        self::assertGreaterThan(time(), $decoded->exp);
    }

    private function spkiPem(string $publicRaw): string
    {
        $der = hex2bin(self::P256_SPKI_PREFIX) . substr($publicRaw, 1);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
