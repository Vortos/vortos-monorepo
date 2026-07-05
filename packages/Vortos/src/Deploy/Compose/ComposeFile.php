<?php

declare(strict_types=1);

namespace Vortos\Deploy\Compose;

use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Target\ActiveColor;

/**
 * Renders the blue/green cutover compose for a single color from the app's real
 * {@see RuntimeServiceSpec}. The colors are internal-only (edge-router topology): the app service
 * publishes NO host ports — it only exposes its container port so the standalone edge (Caddy) can
 * reverse-proxy to app-<color>:<containerPort> over the shared external vortos-net.
 */
final readonly class ComposeFile
{
    public function __construct(
        public string $projectName,
        public ActiveColor $color,
        public ImageReference $image,
        public RuntimeServiceSpec $spec,
    ) {
        if (!$image->isDigestPinned()) {
            throw new \InvalidArgumentException('Compose file image must be digest-pinned.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $imageRef = $this->image->toString();

        $appService = [
            'image' => $imageRef,
            'command' => $this->spec->command,
            // Internal-only: expose (not publish). The edge owns 80/443 and dials this over vortos-net.
            'expose' => [(string) $this->spec->containerPort],
            'networks' => $this->spec->networks,
            'restart' => 'unless-stopped',
        ];

        $workerService = [
            'image' => $imageRef,
            'command' => $this->spec->workerCommand,
            'networks' => $this->spec->networks,
            'restart' => 'unless-stopped',
        ];

        if ($this->spec->envFiles !== []) {
            $appService['env_file'] = $this->spec->envFiles;
            $workerService['env_file'] = $this->spec->envFiles;
        }

        if ($this->spec->environment !== []) {
            // SERVER_NAME etc. shape how the app serves HTTP; the worker doesn't serve HTTP, so it
            // takes only env_file (its real runtime config), never the HTTP-serving overrides.
            $appService['environment'] = $this->spec->environment;
        }

        return [
            'services' => [
                sprintf('app-%s', $this->color->value) => $appService,
                sprintf('worker-%s', $this->color->value) => $workerService,
            ],
            'networks' => array_fill_keys($this->spec->networks, ['external' => true]),
        ];
    }
}
