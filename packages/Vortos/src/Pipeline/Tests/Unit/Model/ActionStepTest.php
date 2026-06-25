<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\ActionStep;
use Vortos\Pipeline\Model\PinnedAction;

final class ActionStepTest extends TestCase
{
    public function test_construction(): void
    {
        $action = new PinnedAction('actions', 'checkout', 'b4ffde65f46336ab88eb53be808477a3936bae11', 'v4');
        $step = new ActionStep('Checkout', $action);

        $this->assertSame('Checkout', $step->name);
        $this->assertSame($action, $step->action);
        $this->assertSame([], $step->with);
    }

    public function test_with_params(): void
    {
        $action = new PinnedAction('shivammathur', 'setup-php', 'c541c155eee45413f5b09a52248675b1a2575231', 'v2');
        $step = new ActionStep('Setup PHP', $action, ['php-version' => '8.5', 'coverage' => 'none']);

        $this->assertSame(['php-version' => '8.5', 'coverage' => 'none'], $step->with);
    }

    public function test_to_array(): void
    {
        $action = new PinnedAction('actions', 'checkout', 'b4ffde65f46336ab88eb53be808477a3936bae11', 'v4');
        $step = new ActionStep('Checkout', $action, ['fetch-depth' => '0'], 'always()');

        $array = $step->toArray();

        $this->assertSame('action', $array['type']);
        $this->assertSame('Checkout', $array['name']);
        $this->assertSame('b4ffde65f46336ab88eb53be808477a3936bae11', $array['action']['sha']);
        $this->assertSame(['fetch-depth' => '0'], $array['with']);
        $this->assertSame('always()', $array['condition']);
    }

    public function test_to_array_sorted_with(): void
    {
        $action = new PinnedAction('actions', 'checkout', 'b4ffde65f46336ab88eb53be808477a3936bae11', 'v4');
        $step = new ActionStep('Checkout', $action, ['z-param' => 'z', 'a-param' => 'a']);

        $array = $step->toArray();
        $keys = array_keys($array['with']);

        $this->assertSame(['a-param', 'z-param'], $keys);
    }

    public function test_empty_name_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $action = new PinnedAction('actions', 'checkout', 'b4ffde65f46336ab88eb53be808477a3936bae11', 'v4');
        new ActionStep('', $action);
    }
}
