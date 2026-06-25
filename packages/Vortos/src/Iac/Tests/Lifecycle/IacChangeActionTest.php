<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\IacChangeAction;

final class IacChangeActionTest extends TestCase
{
    public function test_delete_is_destructive(): void
    {
        $this->assertTrue(IacChangeAction::Delete->isDestructive());
    }

    public function test_replace_is_destructive(): void
    {
        $this->assertTrue(IacChangeAction::Replace->isDestructive());
    }

    public function test_create_is_not_destructive(): void
    {
        $this->assertFalse(IacChangeAction::Create->isDestructive());
    }

    public function test_update_is_not_destructive(): void
    {
        $this->assertFalse(IacChangeAction::Update->isDestructive());
    }

    public function test_noop_is_not_destructive(): void
    {
        $this->assertFalse(IacChangeAction::NoOp->isDestructive());
    }

    public function test_read_is_not_destructive(): void
    {
        $this->assertFalse(IacChangeAction::Read->isDestructive());
    }

    public function test_all_cases_have_string_values(): void
    {
        foreach (IacChangeAction::cases() as $case) {
            $this->assertNotEmpty($case->value);
        }
    }
}
