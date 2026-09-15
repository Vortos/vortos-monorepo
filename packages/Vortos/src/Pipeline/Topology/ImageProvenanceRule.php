<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Topology;

use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Model\ComposeServiceSet;

/**
 * Every image the topology runs must be provably the bytes that were reviewed (RC-9, RC-5).
 *
 * WHY. The backup sidecar — holding the backup token, the backup key and the secret-store identity — ran
 * from a mutable `:main` tag built by a workflow that neither scanned nor signed it, and the database,
 * cache and socket proxy ran floating tags too. A tag is a pointer anyone with push access, or a
 * compromised upstream, can move; `compose pull` then silently runs different bytes. The rule holds each
 * service to one of these provable forms:
 *
 *  - a digest-pinned reference (`name[:tag]@sha256:<64 hex>`), where changing what runs is a reviewed diff;
 *  - exactly `${VORTOS_IMAGE_<NAME>:?…}` for a declared auxiliary image listing this service — built from
 *    the release, scanned, signed, verified and written by the deploy. The `:?` form is required so a
 *    hand-run compose without the deploy's interpolation file fails loudly instead of running nothing;
 *  - a digest from the application repository only on a service a pinned image declares (RC-5): that is the
 *    image the pipeline built, scanned and signed, and whose signature the deploy verifies before release.
 *    An application-repository digest anywhere else is an image nothing vouches for.
 *
 * A service that builds on the host, names no image, or interpolates anything else is refused.
 */
final class ImageProvenanceRule implements TopologyRuleInterface
{
    public const NAME = 'image_provenance';

    private const DIGEST_PINNED = '/^[a-z0-9][a-z0-9._\/:-]*@sha256:[a-f0-9]{64}$/';
    private const AUXILIARY_REFERENCE = '/^\$\{(VORTOS_IMAGE_[A-Z0-9]+):\?[^}]+\}$/';

    /**
     * @param array<string, ComposeServiceSet> $auxiliaryImages compose variable => services allowed to run it
     * @param ?string                          $ownRepository   the application image repository, when it is known
     * @param array<string, ComposeServiceSet> $pinnedImages    pinned image name => services that run it by digest
     */
    public function __construct(
        private readonly array $auxiliaryImages,
        private readonly ?string $ownRepository = null,
        private readonly array $pinnedImages = [],
    ) {}

    public static function forDefinition(PipelineDefinition $definition): self
    {
        $auxiliary = [];
        foreach ($definition->auxiliaryImages as $image) {
            $auxiliary[$image->name->composeVariable()] = $image->services;
        }

        $pinned = [];
        foreach ($definition->pinnedImages as $image) {
            $pinned[$image->name->value] = $image->services;
        }

        return new self($auxiliary, $definition->imageRepository, $pinned);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function evaluate(ComposeTopology $topology): array
    {
        $violations = [];
        /** @var array<string, list<string>> $consumers */
        $consumers = [];
        /** @var array<string, true> $pinnedConsumers */
        $pinnedConsumers = [];

        foreach ($topology->services as $service => $definition) {
            if (\array_key_exists('build', $definition)) {
                $violations[] = $this->violation(ImageProvenanceViolationKind::BuiltOnHost, $service, 'build', 'a production service must run a released image, not build one on the host');
                continue;
            }

            $image = $definition['image'] ?? null;
            if (!\is_string($image) || trim($image) === '') {
                $violations[] = $this->violation(ImageProvenanceViolationKind::MissingImage, $service, 'image', 'the service names no image');
                continue;
            }

            if (preg_match(self::AUXILIARY_REFERENCE, $image, $m) === 1) {
                $allowed = $this->auxiliaryImages[$m[1]] ?? null;
                if ($allowed === null) {
                    $violations[] = $this->violation(ImageProvenanceViolationKind::Unverifiable, $service, $image, sprintf('%s is not a declared auxiliary image (auxiliaryImages in config/pipeline.php)', $m[1]));
                } elseif (!$allowed->contains($service)) {
                    $violations[] = $this->violation(ImageProvenanceViolationKind::ServiceNotAllowed, $service, $image, sprintf('this auxiliary image is declared for [%s] only', implode(', ', $allowed->names)));
                } else {
                    $consumers[$m[1]][] = $service;
                }
                continue;
            }

            if (str_contains($image, '$')) {
                $violations[] = $this->violation(ImageProvenanceViolationKind::Unverifiable, $service, $image, 'the reference interpolates a value the pipeline does not control, so which bytes run cannot be proven; use a digest pin or a declared auxiliary image as ${VORTOS_IMAGE_<NAME>:?...}');
                continue;
            }

            if (preg_match(self::DIGEST_PINNED, $image) !== 1) {
                $violations[] = $this->violation(ImageProvenanceViolationKind::Unpinned, $service, $image, 'a tag is a mutable pointer; pin the exact bytes with @sha256:<digest> so changing what runs is a reviewed diff');
                continue;
            }

            if ($this->ownRepository !== null && self::repositoryOf($image) === $this->ownRepository) {
                if ($this->pinnedImageFor($service) === null) {
                    $violations[] = $this->violation(
                        ImageProvenanceViolationKind::UndeclaredOwnImage,
                        $service,
                        $image,
                        'a digest from the application repository on a service no pinned image declares; nothing built, scanned or signed it for this service, so the deploy cannot vouch for it (pinnedImages in config/pipeline.php)',
                    );
                } else {
                    $pinnedConsumers[$service] = true;
                }
            }
        }

        foreach ($this->auxiliaryImages as $variable => $allowed) {
            foreach ($allowed->names as $service) {
                if (!\in_array($service, $consumers[$variable] ?? [], true)) {
                    $violations[] = $this->violation(ImageProvenanceViolationKind::Unconsumed, $service, $variable, sprintf('declared to run this auxiliary image but its image is not ${%s:?...}', $variable));
                }
            }
        }

        foreach ($this->pinnedImages as $name => $services) {
            foreach ($services->names as $service) {
                if (!isset($pinnedConsumers[$service])) {
                    $violations[] = $this->violation(
                        ImageProvenanceViolationKind::Unconsumed,
                        $service,
                        $name,
                        sprintf('declared to run the pinned image "%s" but its image is not a digest from %s', $name, $this->ownRepository ?? 'the application repository'),
                    );
                }
            }
        }

        return $violations;
    }

    private function pinnedImageFor(string $service): ?string
    {
        foreach ($this->pinnedImages as $name => $services) {
            if ($services->contains($service)) {
                return $name;
            }
        }

        return null;
    }

    /** `registry/path[:tag]@sha256:…` → `registry/path`; a port colon before the last slash is kept. */
    private static function repositoryOf(string $reference): string
    {
        $name = explode('@', $reference, 2)[0];
        $slash = strrpos($name, '/');
        $colon = strrpos($name, ':');

        return $colon !== false && ($slash === false || $colon > $slash) ? substr($name, 0, $colon) : $name;
    }

    private function violation(ImageProvenanceViolationKind $kind, string $service, string $subject, string $detail): TopologyViolation
    {
        return new TopologyViolation(self::NAME, $kind, $service, $subject, $detail);
    }
}
