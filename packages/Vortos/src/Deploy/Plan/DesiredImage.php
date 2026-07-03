<?php

declare(strict_types=1);

namespace Vortos\Deploy\Plan;

use Vortos\Deploy\Registry\ImageReference;

/**
 * Single source of truth for turning a {@see DeployPlan} into the fully-pinned
 * {@see ImageReference} its steps describe. Deploy steps carry both the
 * image_repository and image_digest params (emitted by every strategy), so the
 * repository is never guessed — it is threaded verbatim from the build manifest.
 *
 * Replaces the former per-target buildImageReference() that hardcoded the literal
 * repository "app". No target may construct a runtime ImageReference any other way.
 */
final readonly class DesiredImage
{
    /**
     * The pinned image the plan deploys, or null if the plan carries no pinned image
     * (e.g. an empty/no-op plan). Never returns a partially-specified reference.
     */
    public static function fromPlan(DeployPlan $plan): ?ImageReference
    {
        $repository = self::scan($plan, 'image_repository');
        $digest = self::scan($plan, 'image_digest');

        if ($repository === null || $digest === null || !str_starts_with($digest, 'sha256:')) {
            return null;
        }

        return new ImageReference($repository, digest: $digest);
    }

    public static function digestFromPlan(DeployPlan $plan): string
    {
        return self::scan($plan, 'image_digest') ?? '';
    }

    public static function repositoryFromPlan(DeployPlan $plan): string
    {
        return self::scan($plan, 'image_repository') ?? '';
    }

    private static function scan(DeployPlan $plan, string $param): ?string
    {
        foreach ($plan->phases as $phase) {
            foreach ($phase->steps as $step) {
                $value = $step->params[$param] ?? null;
                if (\is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
