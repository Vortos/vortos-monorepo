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

        $appHealthcheck = $this->spec->resolvedAppHealthcheck();

        $appService = [
            'image' => $imageRef,
            'command' => $this->spec->command,
            // Internal-only: expose (not publish). The edge owns 80/443 and dials this over vortos-net.
            'expose' => [(string) $this->spec->containerPort],
            'networks' => $this->spec->networks,
            'restart' => 'unless-stopped',
            // Explicit READINESS healthcheck (see AppHealthcheck): overrides the base image's inherited,
            // liveness-only HTTP check so the container's health reflects true readiness — the signal the
            // worker gates on below so its consumer fan-out cannot race (and starve) the readiness gate.
            'healthcheck' => $appHealthcheck->toArray(),
        ];

        $workerService = [
            'image' => $imageRef,
            'command' => $this->spec->workerCommand,
            'networks' => $this->spec->networks,
            'restart' => 'unless-stopped',
            // GAP-G: override the base image's inherited HTTP HEALTHCHECK (FrankenPHP curl :2019/metrics)
            // — the worker serves no HTTP, so without this it is reported 'unhealthy' forever. Resolves to
            // a real supervisorctl check for a supervisord worker, or an explicit disable otherwise.
            'healthcheck' => $this->spec->resolvedWorkerHealthcheck()->toArray(),
        ];

        // The worker (and its consumer fan-out) must not start until the app color is genuinely READY.
        // Co-booting them lets a cold-start consumer stampede — offset-reset replays + empty-group
        // rebalances against a single broker — saturate the broker the app needs while booting, so the
        // app cannot answer /health/ready inside the readiness gate and the cutover aborts. Gating the
        // worker on the app's readiness healthcheck holds it (Compose waits for 'service_healthy') until
        // the gate is already satisfiable. When the app healthcheck is disabled (custom non-HTTP app)
        // there is no readiness signal to wait on, so fall back to the prior co-boot behaviour.
        if (!$appHealthcheck->disabled) {
            $workerService['depends_on'] = [
                sprintf('app-%s', $this->color->value) => ['condition' => 'service_healthy'],
            ];
        }

        if ($this->spec->envFiles !== []) {
            $appService['env_file'] = $this->spec->envFiles;
            $workerService['env_file'] = $this->spec->envFiles;
        }

        if ($this->spec->environment !== []) {
            // SERVER_NAME etc. shape how the app serves HTTP; the worker doesn't serve HTTP, so it
            // takes only env_file (its real runtime config), never the HTTP-serving overrides.
            $appService['environment'] = $this->spec->environment;
        }

        // G8: file-shaped secrets are materialised to a tmpfs host path by the deploy one-shot and
        // bind-mounted read-only into both the app and worker color at their declared container path.
        if ($this->spec->fileSecrets !== []) {
            $volumes = array_map(
                static fn ($fileSecret): string => $fileSecret->composeVolume(),
                $this->spec->fileSecrets,
            );
            $appService['volumes'] = $volumes;
            $workerService['volumes'] = $volumes;
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
