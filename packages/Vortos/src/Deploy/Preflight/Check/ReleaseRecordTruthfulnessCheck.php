<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Deploy\State\CurrentReleaseStoreInterface;
use Vortos\Deploy\Target\DeployTargetRegistry;

/**
 * Refuses a deploy when the recorded current release disagrees with what is actually running.
 *
 * WHAT THIS CATCHES
 *
 * A blue-green deploy failed its readiness gate and correctly auto-rolled back, leaving green on the
 * PREVIOUS image. Re-running only the failed deploy job then completed in 27 seconds and reported
 * "status: deployed, health_status: ok" — while restaging nothing. It short-circuited on
 * idempotency, yet still rewrote "current_release" to the DESIRED digest. The control plane then
 * asserted an image was live that was running nowhere.
 *
 * That state is worse than a failed deploy, for two reasons:
 *
 *  1. It reports success. CI is green and operators believe the release shipped. It did not — in
 *     that incident seventeen new Kafka consumers were simply not running.
 *  2. It is self-perpetuating. Because the recorded digest now equals the desired digest, every
 *     subsequent deploy of that digest also no-ops as "already applied". The only escape is to build
 *     a DIFFERENT digest so desired != recorded, which is not something an operator would guess.
 *
 * WHY A PREFLIGHT AND NOT A PLANNING CHANGE
 *
 * Planning already reconciles against reality — PreflightContextFactory takes "currentDigest" from
 * the target's live status, not from the record. What was missing is anything that NOTICES the
 * record has drifted. Detecting it here turns an invisible trap into a fail-closed gate with a
 * remediation, before the deploy mutates anything, and it protects every consumer of the record —
 * rollback, edge reconciliation and image reclamation all read "current_release" and would act on
 * a digest that no container is running.
 *
 * The check is read-only and does not repair the record: a deploy is not the safe place to silently
 * rewrite control-plane state that is already known to be wrong.
 */
final class ReleaseRecordTruthfulnessCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly CurrentReleaseStoreInterface $releases,
        private readonly DeployTargetRegistry $targets,
    ) {}

    public function id(): string
    {
        return 'release.record_matches_reality';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Plan;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        $env = $context->environment->value;
        $recorded = $this->releases->currentRelease($env);

        if ($recorded === null) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                'No current release recorded yet — nothing can disagree with reality.',
            );
        }

        $host = $context->definition->host;

        if (!$this->targets->has($host)) {
            return PreflightFinding::skip(
                $this->id(),
                $this->category(),
                sprintf('Deploy target "%s" is not registered; cannot read what is running.', $host),
            );
        }

        $live = $this->targets->target($host)->status($context->environment);

        // An empty live digest means the target could not determine what is running — that is a
        // different failure (and other gates cover reachability). Do not manufacture a mismatch
        // from an absence of information.
        if ($live->imageDigest === '') {
            return PreflightFinding::skip(
                $this->id(),
                $this->category(),
                'Target reported no running image digest; nothing to compare the record against.',
            );
        }

        if (hash_equals($recorded->imageDigest, $live->imageDigest)) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                sprintf('Recorded release matches the running image (%s).', $live->color->value),
            );
        }

        return PreflightFinding::fail(
            $this->id(),
            $this->category(),
            'The recorded current release does not match the image that is actually running.',
            sprintf(
                'recorded: %s on %s (generation %d) — running: %s on %s',
                $recorded->imageDigest,
                $recorded->activeColor->value,
                $recorded->generation,
                $live->imageDigest,
                $live->color->value,
            ),
            'The control plane believes an image is live that is not. This usually follows a deploy '
            . 'that was re-run after a failed health gate: it short-circuited on idempotency and '
            . 'recorded the desired digest without restaging. Deploying a DIFFERENT digest (rebuild '
            . 'or re-tag) forces a genuine stage/gate/cutover and re-synchronises the record. Do not '
            . 'hand-edit the deploy state — the running image is the truth.',
        );
    }
}
