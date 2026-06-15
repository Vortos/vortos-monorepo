<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\Jwt;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Exception\TokenInvalidException;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Jwt\JwtConfig;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Auth\Jwt\Key\Keyring;
use Vortos\Auth\Jwt\Key\KeyStatus;
use Vortos\Auth\Jwt\Key\SigningKey;
use Vortos\Auth\Storage\InMemoryTokenStorage;

final class JwtRotationTest extends TestCase
{
    private const SECRET_OLD = 'old-secret-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SECRET_NEW = 'new-secret-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function test_issued_token_carries_active_kid_in_header(): void
    {
        $service = $this->serviceWith(new Keyring(
            SigningKey::hs256('key-old', self::SECRET_OLD, KeyStatus::Active),
        ));

        $token  = $service->issue(new UserIdentity('user-1', []));
        $header = $this->header($token->accessToken);

        $this->assertSame('key-old', $header['kid']);
    }

    public function test_token_signed_by_retiring_key_still_validates_after_rotation(): void
    {
        // Issue with the old key as the only Active key.
        $issuer = $this->serviceWith(new Keyring(
            SigningKey::hs256('key-old', self::SECRET_OLD, KeyStatus::Active),
        ));
        $token = $issuer->issue(new UserIdentity('user-1', ['ROLE_USER']));

        // After rotation: old key is Retiring, new key is Active — both verify.
        $rotated = $this->serviceWith(new Keyring(
            SigningKey::hs256('key-old', self::SECRET_OLD, KeyStatus::Retiring),
            SigningKey::hs256('key-new', self::SECRET_NEW, KeyStatus::Active),
        ));

        $validated = $rotated->validate($token->accessToken);
        $this->assertSame('user-1', $validated->identity->id());
    }

    public function test_token_fails_once_its_key_is_dropped_from_the_ring(): void
    {
        $issuer = $this->serviceWith(new Keyring(
            SigningKey::hs256('key-old', self::SECRET_OLD, KeyStatus::Active),
        ));
        $token = $issuer->issue(new UserIdentity('user-1', []));

        // Old key fully removed — only the new key remains.
        $dropped = $this->serviceWith(new Keyring(
            SigningKey::hs256('key-new', self::SECRET_NEW, KeyStatus::Active),
        ));

        $this->expectException(TokenInvalidException::class);
        $dropped->validate($token->accessToken);
    }

    private function serviceWith(Keyring $keyring): JwtService
    {
        return new JwtService(
            new JwtConfig($keyring, issuer: 'test'),
            new InMemoryTokenStorage(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function header(string $jwt): array
    {
        $parts = explode('.', $jwt);
        return (array) json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
    }
}
