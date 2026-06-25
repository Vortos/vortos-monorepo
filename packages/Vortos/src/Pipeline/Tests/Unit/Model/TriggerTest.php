<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\Trigger;
use Vortos\Pipeline\Model\TriggerEvent;

final class TriggerTest extends TestCase
{
    public function test_push_with_branches(): void
    {
        $trigger = new Trigger(TriggerEvent::Push, branches: ['main', 'develop']);

        $this->assertSame(TriggerEvent::Push, $trigger->event);
        $this->assertSame(['main', 'develop'], $trigger->branches);
    }

    public function test_push_with_tags(): void
    {
        $trigger = new Trigger(TriggerEvent::Push, tags: ['v*']);

        $this->assertSame(['v*'], $trigger->tags);
    }

    public function test_pull_request(): void
    {
        $trigger = new Trigger(TriggerEvent::PullRequest);

        $this->assertSame(TriggerEvent::PullRequest, $trigger->event);
        $this->assertSame([], $trigger->branches);
    }

    public function test_workflow_dispatch(): void
    {
        $trigger = new Trigger(TriggerEvent::WorkflowDispatch);

        $this->assertSame(TriggerEvent::WorkflowDispatch, $trigger->event);
    }

    public function test_with_paths(): void
    {
        $trigger = new Trigger(TriggerEvent::Push, paths: ['packages/**']);

        $this->assertSame(['packages/**'], $trigger->paths);
    }

    public function test_to_array_full(): void
    {
        $trigger = new Trigger(TriggerEvent::Push, branches: ['main'], tags: ['v*'], paths: ['src/**']);

        $array = $trigger->toArray();

        $this->assertSame('push', $array['event']);
        $this->assertSame(['main'], $array['branches']);
        $this->assertSame(['v*'], $array['tags']);
        $this->assertSame(['src/**'], $array['paths']);
    }

    public function test_to_array_minimal(): void
    {
        $trigger = new Trigger(TriggerEvent::PullRequest);

        $array = $trigger->toArray();

        $this->assertSame('pull_request', $array['event']);
        $this->assertArrayNotHasKey('branches', $array);
        $this->assertArrayNotHasKey('tags', $array);
        $this->assertArrayNotHasKey('paths', $array);
    }
}
