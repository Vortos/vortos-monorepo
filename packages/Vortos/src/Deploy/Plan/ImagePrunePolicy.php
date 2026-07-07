<?php

declare(strict_types=1);

namespace Vortos\Deploy\Plan;

/**
 * R8-4: how much to reclaim on the target after a successful cutover.
 *
 * A conservative retention: keep the {@see $keep} most-recent images of the deployed repository
 * (which always includes the now-active release and the previous-for-rollback), then remove older
 * superseded release images, dangling layers, and build cache older than {@see $builderCacheMaxAge}.
 * Reclamation is best-effort and must never endanger a healthy release — it is not part of the plan's
 * identity (excluded from the plan hash and preview) and a prune failure never fails a green deploy.
 */
final readonly class ImagePrunePolicy
{
    public function __construct(
        public bool $enabled = true,
        public int $keep = 2,
        public string $builderCacheMaxAge = '168h',
    ) {
        if ($keep < 2) {
            // Never fewer than active + previous-for-rollback.
            throw new \InvalidArgumentException('ImagePrunePolicy keep must be >= 2 (active + previous-for-rollback).');
        }

        if (preg_match('/^\d+[smhd]$/', $builderCacheMaxAge) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'ImagePrunePolicy builderCacheMaxAge must look like "168h"/"7d"/"30m", got "%s".',
                $builderCacheMaxAge,
            ));
        }
    }

    public static function disabled(): self
    {
        return new self(enabled: false);
    }
}
