<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\IacChangeAction;
use Vortos\Iac\Lifecycle\IacResourceChange;

final class IacResourceChangeTest extends TestCase
{
    public function test_is_destructive_for_delete(): void
    {
        $change = new IacResourceChange('a.x', 'a', IacChangeAction::Delete, 'p');
        $this->assertTrue($change->isDestructive());
    }

    public function test_is_destructive_for_replace(): void
    {
        $change = new IacResourceChange('a.x', 'a', IacChangeAction::Replace, 'p');
        $this->assertTrue($change->isDestructive());
    }

    public function test_is_not_destructive_for_create(): void
    {
        $change = new IacResourceChange('a.x', 'a', IacChangeAction::Create, 'p');
        $this->assertFalse($change->isDestructive());
    }

    public function test_is_not_destructive_for_update(): void
    {
        $change = new IacResourceChange('a.x', 'a', IacChangeAction::Update, 'p');
        $this->assertFalse($change->isDestructive());
    }

    public function test_is_not_destructive_for_noop(): void
    {
        $change = new IacResourceChange('a.x', 'a', IacChangeAction::NoOp, 'p');
        $this->assertFalse($change->isDestructive());
    }

    public function test_is_not_destructive_for_read(): void
    {
        $change = new IacResourceChange('a.x', 'a', IacChangeAction::Read, 'p');
        $this->assertFalse($change->isDestructive());
    }

    public function test_empty_address_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacResourceChange('', 'a', IacChangeAction::Create, 'p');
    }

    public function test_properties_are_set(): void
    {
        $change = new IacResourceChange('aws_instance.web', 'aws_instance', IacChangeAction::Update, 'hashicorp/aws');
        $this->assertSame('aws_instance.web', $change->address);
        $this->assertSame('aws_instance', $change->type);
        $this->assertSame(IacChangeAction::Update, $change->action);
        $this->assertSame('hashicorp/aws', $change->provider);
    }
}
