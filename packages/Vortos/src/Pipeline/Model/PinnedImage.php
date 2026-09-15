<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * An image the pipeline builds on demand and the topology runs by a digest committed to the repository
 * (RC-5) — a datastore image such as PostgreSQL with its configuration and tools baked in.
 *
 * WHY NOT AN AUXILIARY IMAGE. An {@see AuxiliaryImage} is rebuilt and rolled out with every release, which
 * is right for a sidecar that runs the release's code and wrong for a database: new bytes every release
 * would mean the datastore either restarts on every deploy or silently drifts from its declared image.
 * A pinned image changes only when its build context changes. The generated `images.yml` workflow builds
 * it (native arch, SBOM, CVE gate, keyless signature verified in place) and reports the digest; the digest
 * is committed literally in the compose topology, so switching the database to new bytes is a reviewed
 * diff and a deliberate recreate. The deploy verifies the signature of every image from the application
 * repository that the topology references before it releases anything.
 *
 * WHO MAY VOUCH. The signature is accepted only from {@see self::WORKFLOW_PATH}: a digest committed as the
 * database must have been built by the workflow that runs the database image's gates, not by any workflow
 * in the repository. Any ref may build it — a push to a branch touching the build context produces a signed
 * digest before the release that switches the topology to it exists, which is the only order that works
 * (the topology rule refuses a declared pinned image nothing runs). The review point is the commit of the
 * digest into the deployment branch, never the build.
 */
final readonly class PinnedImage
{
    /** The generated workflow that builds every pinned image; the only signer the deploy accepts for one. */
    public const WORKFLOW_PATH = '.github/workflows/images.yml';

    /** The cosign certificate identity of {@see self::WORKFLOW_PATH} on any ref of this repository. */
    public static function signerIdentityRegexp(): string
    {
        return '^${{ github.server_url }}/${{ github.repository }}/' . str_replace('.', '\.', self::WORKFLOW_PATH) . '@refs/';
    }

    public function __construct(
        /** Slug; the build tag is `:pinned-<name>-<sha>` in the application repository. */
        public AuxiliaryImageName $name,
        public ProjectPath $dockerfile,
        /** Build context; a push touching it (or the Dockerfile) rebuilds the image. */
        public ProjectPath $context,
        /** The compose services that run it by digest. */
        public ComposeServiceSet $services,
        /** Project-relative Trivy ignore file for this image's scan only; null = no waivers. */
        public ?string $scanIgnoreFile = null,
    ) {
        if ($scanIgnoreFile !== null
            && (preg_match('#^[A-Za-z0-9_.][A-Za-z0-9_.-]*(/[A-Za-z0-9_.][A-Za-z0-9_.-]*)*$#', $scanIgnoreFile) !== 1
                || preg_match('#(^|/)\.\.?(/|$)#', $scanIgnoreFile) === 1)) {
            throw new \InvalidArgumentException(sprintf(
                'Pinned image "%s" scan ignore file must be a project-relative path without traversal or shell metacharacters, got "%s".',
                $name->value,
                $scanIgnoreFile,
            ));
        }
    }

    /** The mutable build tag; the topology never references it, only the digest it resolves to. */
    public function buildTag(string $repository): string
    {
        return sprintf('%s:pinned-%s-${{ github.sha }}', $repository, $this->name->value);
    }
}
