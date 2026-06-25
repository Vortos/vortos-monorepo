<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\ActionStep;
use Vortos\Pipeline\Model\CommandStep;
use Vortos\Pipeline\Model\PinnedAction;

final class StepIdEnvTest extends TestCase
{
    public function test_command_step_id_rendered_when_set(): void
    {
        $step = new CommandStep('build', 'docker build', id: 'build');
        $array = $step->toArray();
        $this->assertSame('build', $array['id']);
    }

    public function test_command_step_id_omitted_when_null(): void
    {
        $step = new CommandStep('build', 'docker build');
        $array = $step->toArray();
        $this->assertArrayNotHasKey('id', $array);
    }

    public function test_command_step_env_rendered_when_non_empty(): void
    {
        $step = new CommandStep('build', 'docker build', env: ['FOO' => 'bar', 'BAZ' => 'qux']);
        $array = $step->toArray();
        $this->assertSame(['BAZ' => 'qux', 'FOO' => 'bar'], $array['env']);
    }

    public function test_command_step_env_omitted_when_empty(): void
    {
        $step = new CommandStep('build', 'docker build');
        $array = $step->toArray();
        $this->assertArrayNotHasKey('env', $array);
    }

    public function test_command_step_bad_id_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Step id must match');

        new CommandStep('build', 'docker build', id: 'BAD ID');
    }

    public function test_command_step_id_starting_with_number_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CommandStep('build', 'docker build', id: '1bad');
    }

    public function test_action_step_id_rendered_when_set(): void
    {
        $action = new PinnedAction('actions', 'checkout', 'b4ffde65f46336ab88eb53be808477a3936bae11', 'v4');
        $step = new ActionStep('Checkout', $action, id: 'checkout');
        $array = $step->toArray();
        $this->assertSame('checkout', $array['id']);
    }

    public function test_action_step_id_omitted_when_null(): void
    {
        $action = new PinnedAction('actions', 'checkout', 'b4ffde65f46336ab88eb53be808477a3936bae11', 'v4');
        $step = new ActionStep('Checkout', $action);
        $array = $step->toArray();
        $this->assertArrayNotHasKey('id', $array);
    }

    public function test_action_step_env_rendered_when_non_empty(): void
    {
        $action = new PinnedAction('actions', 'checkout', 'b4ffde65f46336ab88eb53be808477a3936bae11', 'v4');
        $step = new ActionStep('Checkout', $action, env: ['TOKEN' => '${{ secrets.GH_TOKEN }}']);
        $array = $step->toArray();
        $this->assertArrayHasKey('env', $array);
        $this->assertSame(['TOKEN' => '${{ secrets.GH_TOKEN }}'], $array['env']);
    }

    public function test_action_step_bad_id_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $action = new PinnedAction('actions', 'checkout', 'b4ffde65f46336ab88eb53be808477a3936bae11', 'v4');
        new ActionStep('Checkout', $action, id: 'INVALID');
    }

    public function test_existing_call_sites_not_broken(): void
    {
        $cmd = new CommandStep('Install', 'composer install', '/app', 'always()');
        $this->assertSame('Install', $cmd->name);
        $this->assertSame('composer install', $cmd->run);
        $this->assertSame('/app', $cmd->workingDirectory);
        $this->assertSame('always()', $cmd->condition);
        $this->assertNull($cmd->id);
        $this->assertSame([], $cmd->env);

        $action = new PinnedAction('actions', 'checkout', 'b4ffde65f46336ab88eb53be808477a3936bae11', 'v4');
        $act = new ActionStep('Checkout', $action, ['fetch-depth' => '0'], 'always()');
        $this->assertSame('Checkout', $act->name);
        $this->assertSame(['fetch-depth' => '0'], $act->with);
        $this->assertSame('always()', $act->condition);
        $this->assertNull($act->id);
        $this->assertSame([], $act->env);
    }
}
