<?php

declare(strict_types=1);

namespace Vortos\Deploy\Compose;

use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Target\ActiveColor;

/**
 * Builds the per-color cutover compose and the color endpoint from the app's {@see RuntimeServiceSpec}.
 * The endpoint (host app-<color>, internal container port) is the single source of truth shared by
 * the readiness gate and the Caddy upstream dial — so a color that comes up healthy is the exact one
 * the edge routes to.
 */
final readonly class ComposeProjectFactory
{
    public function __construct(private RuntimeServiceSpec $spec)
    {
    }

    public function create(ActiveColor $color, ImageReference $image): ComposeFile
    {
        return new ComposeFile(
            projectName: sprintf('vortos-app-%s', $color->value),
            color: $color,
            image: $image,
            spec: $this->spec,
        );
    }

    public function endpointFor(ActiveColor $color): ColorEndpoint
    {
        return new ColorEndpoint(
            host: sprintf('app-%s', $color->value),
            port: $this->spec->containerPort,
        );
    }
}
