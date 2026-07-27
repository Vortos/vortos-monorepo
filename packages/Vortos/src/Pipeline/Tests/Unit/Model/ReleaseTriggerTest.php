<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Definition\PipelineDefinitionBuilder;
use Vortos\Pipeline\Model\ReleaseTrigger;
use Vortos\Pipeline\Model\TriggerEvent;

/**
 * The `on:` block and every deploy-bearing job condition must describe the SAME release.
 *
 * They used to be written independently, so a tag-only deployment had to be produced by
 * hand-editing the generated workflow. Regenerating reverted the edit, and because the trigger
 * still matched a tag while the job condition no longer did, the workflow ran and deployed
 * nothing — a green run that shipped nothing at all.
 */
final class ReleaseTriggerTest extends TestCase
{
    public function test_tag_mode_conditions_never_mention_a_branch(): void
    {
        $condition = ReleaseTrigger::Tag->jobCondition('main');

        self::assertSame("github.ref_type == 'tag'", $condition);
        self::assertStringNotContainsString('refs/heads', $condition);
    }

    public function test_branch_mode_keeps_the_historical_condition(): void
    {
        self::assertSame(
            "github.ref == 'refs/heads/main' && github.event_name == 'push'",
            ReleaseTrigger::Branch->jobCondition('main'),
        );
    }

    public function test_tag_mode_drops_pull_request_runs(): void
    {
        self::assertFalse(ReleaseTrigger::Tag->includesPullRequests());
        self::assertTrue(ReleaseTrigger::Branch->includesPullRequests());
    }

    public function test_tag_mode_emits_no_branch_trigger(): void
    {
        $pipeline = $this->pipelineFor(ReleaseTrigger::Tag);

        foreach ($pipeline->triggers as $trigger) {
            self::assertNotSame(
                TriggerEvent::PullRequest,
                $trigger->event,
                'A pull request can never produce a tag; a PR trigger here only adds a second place '
                . 'for the release condition to disagree.',
            );
            self::assertSame([], $trigger->branches, 'tag-only releases must not trigger on a branch');
        }
    }

    public function test_branch_mode_still_triggers_on_the_branch_and_on_tags(): void
    {
        $pipeline = $this->pipelineFor(ReleaseTrigger::Branch);

        $push = null;
        foreach ($pipeline->triggers as $trigger) {
            if ($trigger->event === TriggerEvent::Push) {
                $push = $trigger;
            }
        }

        self::assertNotNull($push);
        self::assertSame(['main'], $push->branches);
        self::assertSame(['*'], $push->tags);
    }

    /**
     * The whole point: whatever the mode, the emitted trigger and the emitted job condition must
     * agree about what a release is.
     */
    public function test_the_trigger_and_the_job_conditions_agree(): void
    {
        foreach ([ReleaseTrigger::Tag, ReleaseTrigger::Branch] as $mode) {
            $pipeline = $this->pipelineFor($mode);

            $triggersOnBranch = false;
            foreach ($pipeline->triggers as $trigger) {
                if ($trigger->branches !== []) {
                    $triggersOnBranch = true;
                }
            }

            $conditionMentionsBranch = str_contains($mode->jobCondition('main'), 'refs/heads');

            self::assertSame(
                $triggersOnBranch,
                $conditionMentionsBranch,
                sprintf('mode "%s": the on: block and the job condition disagree about a branch release.', $mode->value),
            );
        }
    }

    private function pipelineFor(ReleaseTrigger $mode): object
    {
        $definition = (new PipelineDefinitionBuilder())
            ->deploymentBranch('main')
            ->releaseTrigger($mode)
            ->build();

        return (new PipelineBuilder(new StageGate()))->build($definition);
    }
}
