<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Build;

/**
 * Resolves the base image digest via `docker buildx imagetools inspect` against the registry the
 * base image is pulled from, at build time. Reproducibility is guaranteed without the app pinning
 * a digest by hand; the warning only fires when resolution genuinely cannot happen (e.g. the
 * Dockerfile has no resolvable FROM, or the registry is unreachable).
 */
final class RegistryBaseImageDigestResolver implements BaseImageDigestResolverInterface
{
    public function generate(string $dockerfilePath): string
    {
        // Single-quoted heredoc-safe: $VARs are shell runtime vars, not PHP. Only {$dockerfilePath}
        // is interpolated by PHP.
        $df = $dockerfilePath;

        return <<<BASH
            DOCKERFILE="{$df}"
            if [ ! -f "\$DOCKERFILE" ]; then
              echo "::warning::Dockerfile \$DOCKERFILE not found — base image digest not pinned; build reproducibility is not guaranteed."
              exit 0
            fi
            # Final FROM wins (last build stage's base). Strip any existing @digest / :tag is kept for lookup.
            BASE_REF=\$(grep -iE '^[[:space:]]*FROM[[:space:]]' "\$DOCKERFILE" | grep -viE 'AS[[:space:]]+builder' | tail -1 | awk '{print \$2}')
            if [ -z "\$BASE_REF" ]; then
              echo "::warning::Could not determine base image from \$DOCKERFILE — digest not pinned; build reproducibility is not guaranteed."
              exit 0
            fi
            # buildx is always set up earlier in the build job (Set up Docker Buildx step).
            DIGEST=\$(docker buildx imagetools inspect "\$BASE_REF" --format '{{json .Manifest.Digest}}' 2>/dev/null | tr -d '"')
            if [ -z "\$DIGEST" ]; then
              echo "::warning::Could not resolve a digest for base image \$BASE_REF — build reproducibility is not guaranteed."
              exit 0
            fi
            echo "Resolved base image \$BASE_REF -> \$DIGEST"
            echo "BASE_IMAGE_DIGEST=\$DIGEST" >> "\$GITHUB_ENV"
            BASH;
    }
}
