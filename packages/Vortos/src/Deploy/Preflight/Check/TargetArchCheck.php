<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Deploy\Target\DeployTargetRegistry;

/**
 * Fail-closed arch gate (§12.5): the definition, the build it deploys, and the
 * target must all agree on CPU architecture. An x86 image can never silently reach
 * an Ampere host.
 *
 *  - definition.arch ≠ manifest.targetArch          → Fail (deploying the wrong build)
 *  - manifest.targetArch ≠ target's arch constraint → Fail (build won't run on host)
 *  - target declares no arch constraint             → Skip (gate provably N/A there),
 *    once definition and manifest already agree
 */
final class TargetArchCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly DeployTargetRegistry $targets,
    ) {}

    public function id(): string
    {
        return 'arch.aligned';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Arch;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        $def = $context->definition;
        $manifestArch = $context->desiredManifest->targetArch;

        if ($def->arch !== $manifestArch) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'definition architecture does not match the build manifest',
                sprintf('definition=%s manifest=%s', $def->arch->value, $manifestArch->value),
                'Build the image for the declared target architecture, or correct the arch in config/deploy.php.',
            );
        }

        if (!$this->targets->has($def->host)) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'cannot validate target architecture: target not registered',
                sprintf('host=%s', $def->host),
                'Resolve the driver-set failure first.',
            );
        }

        $constraint = $this->targets->target($def->host)->capabilities()->constraint('target_arch');

        if ($constraint === null) {
            return PreflightFinding::skip(
                $this->id(),
                $this->category(),
                sprintf('target "%s" declares no arch constraint', $def->host),
                sprintf('definition and manifest agree on %s', $def->arch->value),
            );
        }

        if ((string) $constraint !== $manifestArch->value) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'build architecture does not match the target arch constraint',
                sprintf('manifest=%s target_constraint=%s', $manifestArch->value, (string) $constraint),
                'Build for the target architecture or deploy to a target that matches the build.',
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            sprintf('architecture aligned across definition, build, and target (%s)', $manifestArch->value),
        );
    }
}
