<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * What causes a release: a push to the deployment branch, or a tag.
 *
 * WHY THIS EXISTS
 *
 * The emitted workflow expressed this in two places that had to agree — the `on:` block and every
 * job's `if:` condition — and nothing tied them together. A deployment that releases only on tags
 * had to be produced by hand-editing the generated file: drop `branches`, drop `pull_request`, and
 * rewrite the deploy job's condition. Regenerating the pipeline silently reverted all three, and
 * because the `on:` block still matched a tag while the job condition no longer did, the result was
 * a workflow that ran and deployed NOTHING while reporting success.
 *
 * Hand-edits to a generated file are exactly the failure mode the preCutoverCommands seam was added
 * to remove; this closes the same gap for the release trigger.
 */
enum ReleaseTrigger: string
{
    /**
     * Release on a push to the deployment branch. Tags still build (so a tag is publishable) but the
     * branch push is what deploys. This is the historical default and stays the default.
     */
    case Branch = 'branch';

    /**
     * Release only on a tag. The deployment branch no longer triggers a deploy, so an accidental
     * merge cannot ship — a release is an explicit, named act.
     */
    case Tag = 'tag';

    /** The `if:` expression a deploy-bearing job must carry under this mode. */
    public function jobCondition(string $deploymentBranch): string
    {
        return match ($this) {
            self::Tag => "github.ref_type == 'tag'",
            self::Branch => sprintf(
                "github.ref == 'refs/heads/%s' && github.event_name == 'push'",
                $deploymentBranch,
            ),
        };
    }

    /**
     * Whether the workflow should still run on pull requests.
     *
     * Tag-only releases keep PR runs off the deploy workflow: a PR can never produce a tag, so a PR
     * trigger there only burns minutes and adds a second place for the condition to disagree.
     */
    public function includesPullRequests(): bool
    {
        return $this === self::Branch;
    }
}
