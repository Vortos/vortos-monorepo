<?php

declare(strict_types=1);

namespace Vortos\Cache\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Cache\Adapter\RedisConnectionFactory;

/**
 * Signing in to Redis as an ACL user (Redis 6+), against a real server.
 *
 * Needs VORTOS_TEST_REDIS_ACL_HOST: a Redis whose `default` user is off and which has the user
 * `app` / password `p@ss:w/rd` limited to `~app:*`. Skipped without it.
 */
final class RedisConnectionFactoryAclTest extends TestCase
{
    private string $host;

    protected function setUp(): void
    {
        $host = getenv('VORTOS_TEST_REDIS_ACL_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('VORTOS_TEST_REDIS_ACL_HOST is not set.');
        }
        $this->host = $host;
    }

    public function test_an_acl_user_signs_in_and_is_held_to_its_keys(): void
    {
        $redis = RedisConnectionFactory::fromDsn('redis://app:' . rawurlencode('p@ss:w/rd') . '@' . $this->host . ':6379');

        self::assertTrue($redis->set('app:probe', '1'));
        self::assertSame('app', $redis->rawCommand('ACL', 'WHOAMI'));

        $this->expectException(\RedisException::class);
        $redis->set('other:probe', '1');
    }

    public function test_a_wrong_password_is_refused_and_never_echoed(): void
    {
        try {
            RedisConnectionFactory::fromDsn('redis://app:wrong-secret@' . $this->host . ':6379');
            self::fail('A wrong password was accepted.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('user "app"', $e->getMessage());
            self::assertStringNotContainsString('wrong-secret', $e->getMessage());
        }
    }

    public function test_without_credentials_the_disabled_default_user_cannot_work(): void
    {
        $redis = RedisConnectionFactory::fromDsn('redis://' . $this->host . ':6379');

        $this->expectException(\RedisException::class);
        $redis->get('app:probe');
    }

    public function test_a_user_without_a_password_is_a_configuration_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RedisConnectionFactory::fromDsn('redis://app@' . $this->host . ':6379');
    }

    public function test_messages_mask_the_password(): void
    {
        self::assertSame('redis://app:***@redis:6379', RedisConnectionFactory::redact('redis://app:s3cret@redis:6379'));
        self::assertSame('redis://:***@redis:6379/1', RedisConnectionFactory::redact('redis://:s3cret@redis:6379/1'));
        self::assertSame('redis://redis:6379', RedisConnectionFactory::redact('redis://redis:6379'));

        try {
            RedisConnectionFactory::fromDsn('redis://app:s3cret@');
            self::fail('A DSN without a host was accepted.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('s3cret', $e->getMessage());
        }
    }
}
