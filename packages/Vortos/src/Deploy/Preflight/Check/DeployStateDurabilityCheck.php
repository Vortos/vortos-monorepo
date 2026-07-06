<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;

/**
 * Fail-closed deploy-state durability gate (GAP-I).
 *
 * The deploy/release control-plane state (active color, generation, image digest, contract-soak
 * ledger, pull-agent freshness, reconcile rate-limit) is written to the store selected by
 * DEPLOY_STATE_STORE. In the deploy-in-image topology the deploy runs as a 'docker run --rm'
 * one-shot, so a container-local FILE store (%kernel.project_dir%/var/deploy-state) is destroyed
 * after every run — the current-release record is lost, blue-green never alternates color, and
 * 'deploy:rollback' can't see the live release. This gate catches that misconfiguration BEFORE a
 * deploy mutates anything:
 *
 *   - store is redis/mongo (durable)                         → Pass
 *   - store is file, single-node / no push host              → Pass (a persistent host file is fine)
 *   - store is file AND push delivery to a remote host       → Fail (ephemeral one-shot loses state)
 *   - store is redis but no REDIS_* connection is configured  → Fail (durable store won't actually persist)
 *
 * Read-only: it inspects configuration only. A store that is selected but genuinely unreachable at
 * deploy time still fails loud at first use; this gate turns the most common footgun into an
 * actionable preflight failure rather than a silent same-color redeploy.
 */
final class DeployStateDurabilityCheck implements PreflightCheckInterface
{
    private const DURABLE_KINDS = ['redis', 'mongo'];

    public function __construct(
        private readonly string $stateStoreKind,
        private readonly bool $pushDelivery,
        private readonly bool $hasRemoteHost,
        private readonly bool $redisConfigured,
    ) {}

    public function id(): string
    {
        return 'state.durable_store';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Capability;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        $kind = strtolower($this->stateStoreKind);

        if ($kind === 'redis' && !$this->redisConfigured) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'DEPLOY_STATE_STORE=redis but no Redis connection is configured',
                'The durable deploy-state store is Redis, but neither REDIS_DSN nor REDIS_HOST is set, so '
                . 'the store cannot persist the current-release record — blue-green color alternation and '
                . 'rollback would silently break in the --rm deploy one-shot.',
                'Set REDIS_DSN (or REDIS_HOST/REDIS_PORT) in the deploy environment, or set '
                . 'DEPLOY_STATE_STORE=file only for a single-node box with a persistent var/deploy-state.',
            );
        }

        if (in_array($kind, self::DURABLE_KINDS, true)) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                sprintf('deploy-state store "%s" is durable across the --rm deploy one-shot', $kind),
            );
        }

        // kind === 'file' (or an unknown value, treated conservatively as non-durable).
        if ($this->pushDelivery && $this->hasRemoteHost) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'DEPLOY_STATE_STORE=file is ephemeral in the deploy-in-image one-shot topology',
                'A file deploy-state store lives at %kernel.project_dir%/var/deploy-state — inside the '
                . 'docker run --rm deploy one-shot, which is destroyed after every run. The current-release '
                . 'record (active color, generation, image digest) is lost each deploy, so blue-green never '
                . 'alternates color and deploy:rollback cannot see the live release.',
                'Set DEPLOY_STATE_STORE=redis (default) so the control-plane state persists across the '
                . 'ephemeral one-shot — the same durable store the edge already uses.',
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            'file deploy-state store on a single-node / non-push topology persists across runs',
        );
    }
}
