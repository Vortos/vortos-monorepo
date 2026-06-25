<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\LifecyclePhase;

final class LifecyclePhaseTest extends TestCase
{
    public function test_all_phases_have_string_values(): void
    {
        $this->assertSame('init', LifecyclePhase::Init->value);
        $this->assertSame('plan', LifecyclePhase::Plan->value);
        $this->assertSame('apply', LifecyclePhase::Apply->value);
        $this->assertSame('destroy', LifecyclePhase::Destroy->value);
        $this->assertSame('show', LifecyclePhase::Show->value);
    }

    public function test_from_string(): void
    {
        $this->assertSame(LifecyclePhase::Plan, LifecyclePhase::from('plan'));
    }
}
