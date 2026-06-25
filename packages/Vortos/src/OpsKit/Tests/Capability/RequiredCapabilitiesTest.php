<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Capability;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\OpsKit\Driver\Capability\RequiredCapabilities;
use Vortos\OpsKit\Tests\Fixtures\Demo\GreeterCapability;

final class RequiredCapabilitiesTest extends TestCase
{
    public function test_none_is_empty(): void
    {
        $required = RequiredCapabilities::none();
        $this->assertSame([], $required->capabilities);
        $this->assertSame([], $required->constraints);
    }

    public function test_require_is_idempotent_and_immutable(): void
    {
        $a = RequiredCapabilities::none();
        $b = $a->require('x');
        $c = $b->require('x');

        $this->assertSame([], $a->capabilities, 'original must be unchanged (immutable)');
        $this->assertSame(['x'], $b->capabilities);
        $this->assertNotSame($a, $b);
        $this->assertSame(['x'], $c->capabilities, 'requiring the same capability twice is a no-op');
    }

    public function test_of_builds_from_keys_and_constraints(): void
    {
        $required = RequiredCapabilities::of([GreeterCapability::Shouts, 'extra'], ['n' => 2]);

        $this->assertSame(['shouts', 'extra'], $required->capabilities);
        $this->assertSame(['n' => 2], $required->constraints);
    }

    public function test_empty_capability_key_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequiredCapabilities::none()->require('');
    }

    public function test_empty_constraint_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequiredCapabilities::none()->withConstraint('', 1);
    }
}
