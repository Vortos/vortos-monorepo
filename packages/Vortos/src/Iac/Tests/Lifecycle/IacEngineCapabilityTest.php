<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\IacEngineCapability;

final class IacEngineCapabilityTest extends TestCase
{
    public function test_key_matches_value(): void
    {
        foreach (IacEngineCapability::cases() as $cap) {
            $this->assertSame($cap->value, $cap->key());
        }
    }

    public function test_direct_provision_exists(): void
    {
        $this->assertSame('direct_provision', IacEngineCapability::DirectProvision->key());
    }
}
