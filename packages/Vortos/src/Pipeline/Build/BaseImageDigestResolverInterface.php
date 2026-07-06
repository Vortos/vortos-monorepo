<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Build;

/**
 * Generates the CI shell step that resolves the base image's current digest at build time and
 * exports it as BASE_IMAGE_DIGEST, so builds are reproducible without the app hand-maintaining a
 * sha256 in its PipelineDefinition (R7-5).
 *
 * Resolution runs in the build job (where a container runtime and registry access exist), not at
 * workflow-generation time.
 */
interface BaseImageDigestResolverInterface
{
    /**
     * @return string a POSIX shell script that resolves the base image referenced by the final
     *                FROM in $dockerfilePath and appends `BASE_IMAGE_DIGEST=<sha256:…>` to
     *                $GITHUB_ENV. If resolution is impossible it emits a `::warning::` and exits 0
     *                (non-fatal — the build proceeds unpinned).
     */
    public function generate(string $dockerfilePath): string;
}
