<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * A second long-running image built from the release (RC-9) — e.g. a backup/scheduler sidecar that is
 * the serving image plus database client tools.
 *
 * WHY. The pipeline modelled exactly one image, so the backup sidecar — the container holding the
 * backup bucket token, the key that opens the backups and the secret-store identity — was built by a
 * hand-written workflow with no vulnerability scan, no SBOM and no signature, pushed to a second
 * registry account, and run from a mutable `:main` tag nothing verified. The most privileged image was
 * the least verified.
 *
 * Declared here, it is built FROM the exact serving digest (passed as {@see $releaseImageArg}), pushed as
 * a sibling `:sha-<sha>-<name>` tag in the same repository, SBOM-attested, CVE-gated and keyless-signed
 * like the serving image, signature-verified again before release, and run by {@see $services} from its
 * digest: the deploy writes `VORTOS_IMAGE_<NAME>=<repo>@<digest>` into the compose interpolation file,
 * converges exactly those services after the cutover, and fails unless each runs that image and is
 * healthy. The topology policy refuses a service image that is neither digest-pinned nor one of these.
 */
final readonly class AuxiliaryImage
{
    public function __construct(
        public AuxiliaryImageName $name,
        public ProjectPath $dockerfile,
        /** Build argument the Dockerfile's FROM consumes, receiving `<repo>@<serving digest>`. */
        public string $releaseImageArg,
        public ComposeServiceSet $services,
        /**
         * Project-relative Trivy ignore file for this image's scan only; null = no waivers. A plain
         * validated path rather than a {@see ProjectPath}, because ignore files are hidden by convention.
         */
        public ?string $scanIgnoreFile = null,
    ) {
        if ($scanIgnoreFile !== null
            && (preg_match('#^[A-Za-z0-9_.][A-Za-z0-9_.-]*(/[A-Za-z0-9_.][A-Za-z0-9_.-]*)*$#', $scanIgnoreFile) !== 1
                || preg_match('#(^|/)\.\.?(/|$)#', $scanIgnoreFile) === 1)) {
            throw new \InvalidArgumentException(sprintf(
                'Auxiliary image "%s" scan ignore file must be a project-relative path without traversal or shell metacharacters, got "%s".',
                $name->value,
                $scanIgnoreFile,
            ));
        }

        if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $releaseImageArg) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Auxiliary image "%s" release image build arg must be an UPPER_SNAKE name, got "%s".',
                $name->value,
                $releaseImageArg,
            ));
        }
    }
}
