<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Vortos\Analytics\Privacy\PropertyAllowlist;

final class PropertyAllowlistTest extends TestCase
{
    public function test_empty_allowlist_drops_everything(): void
    {
        $allowlist = new PropertyAllowlist([]);
        $this->assertSame([], $allowlist->filter(['email' => 'a@b.com', 'plan' => 'pro']));
    }

    public function test_unknown_keys_are_dropped(): void
    {
        $allowlist = new PropertyAllowlist(['plan']);
        $this->assertSame(['plan' => 'pro'], $allowlist->filter(['plan' => 'pro', 'email' => 'a@b.com']));
    }

    public function test_allowed_keys_survive_with_original_values(): void
    {
        $allowlist = new PropertyAllowlist(['plan', 'seats']);
        $this->assertSame(
            ['plan' => 'pro', 'seats' => 10],
            $allowlist->filter(['plan' => 'pro', 'seats' => 10, 'secret' => 'x']),
        );
    }

    public function test_missing_allowed_key_is_simply_absent(): void
    {
        $allowlist = new PropertyAllowlist(['plan', 'seats']);
        $this->assertSame(['plan' => 'pro'], $allowlist->filter(['plan' => 'pro']));
    }

    public function test_allowed_keys_accessor(): void
    {
        $allowlist = new PropertyAllowlist(['plan']);
        $this->assertSame(['plan'], $allowlist->allowedKeys());
    }
}
