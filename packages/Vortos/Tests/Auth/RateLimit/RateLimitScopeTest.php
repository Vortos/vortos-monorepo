<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\RateLimit;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\RateLimit\RateLimitScope;

final class RateLimitScopeTest extends TestCase
{
    public function test_has_user_scope(): void
    {
        $this->assertSame('user', RateLimitScope::User->value);
    }

    public function test_has_ip_scope(): void
    {
        $this->assertSame('ip', RateLimitScope::Ip->value);
    }

    public function test_has_global_scope(): void
    {
        $this->assertSame('global', RateLimitScope::Global->value);
    }
}
