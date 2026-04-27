<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\TwoFactor;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\TwoFactor\Attribute\Requires2FA;

final class Requires2FAAttributeTest extends TestCase
{
    public function test_is_attribute(): void
    {
        $reflection = new \ReflectionClass(Requires2FA::class);
        $this->assertNotEmpty($reflection->getAttributes(\Attribute::class));
    }

    public function test_targets_class_and_method(): void
    {
        $reflection = new \ReflectionClass(Requires2FA::class);
        $flags = $reflection->getAttributes(\Attribute::class)[0]->newInstance()->flags;
        $this->assertTrue((bool)($flags & \Attribute::TARGET_CLASS));
        $this->assertTrue((bool)($flags & \Attribute::TARGET_METHOD));
    }
}
