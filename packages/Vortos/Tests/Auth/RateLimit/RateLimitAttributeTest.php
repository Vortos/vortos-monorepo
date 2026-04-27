<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\RateLimit;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\RateLimit\Attribute\RateLimit;
use Vortos\Auth\RateLimit\RateLimitScope;

final class RateLimitAttributeTest extends TestCase
{
    public function test_stores_policy_class(): void
    {
        $attr = new RateLimit('App\Policy\MyPolicy');
        $this->assertSame('App\Policy\MyPolicy', $attr->policy);
    }

    public function test_default_scope_is_user(): void
    {
        $attr = new RateLimit('App\Policy\MyPolicy');
        $this->assertSame(RateLimitScope::User, $attr->per);
    }

    public function test_custom_scope(): void
    {
        $attr = new RateLimit('App\Policy\MyPolicy', RateLimitScope::Ip);
        $this->assertSame(RateLimitScope::Ip, $attr->per);
    }

    public function test_is_repeatable(): void
    {
        $reflection = new \ReflectionClass(RateLimit::class);
        $attrs = $reflection->getAttributes(\Attribute::class);
        $flags = $attrs[0]->newInstance()->flags;
        $this->assertTrue((bool)($flags & \Attribute::IS_REPEATABLE));
    }
}
