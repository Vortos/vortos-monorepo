<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\SshCompose;

use Vortos\Deploy\Cutover\Edge\EdgeBaseConfigResolver;
use Vortos\Deploy\Cutover\EdgeConfigGenerator;
use Vortos\Deploy\Execution\RemoteCommand;
use Vortos\Deploy\Execution\SshTransportInterface;

/**
 * Idempotently converges the edge service (compose) on the target as part of every deploy.
 *
 * Closes a real gap: the CI deploy ups the app-color compose and runs the cutover, but never
 * renders/delivers/ups the edge compose — that was produced solely by the one-off deploy:edge:init.
 * So "edit config -> just deploy -> edge updates" did not flow end-to-end. This reconciler delivers
 * the rendered edge compose and brings it up on every deploy, BUT only recreates when something
 * actually changed: it hashes the rendered compose + the operator base config + the adapt image and
 * compares to a marker on the box. An unchanged deploy is a no-op, so the edge never bounces (a brief
 * outage) for nothing.
 *
 * It complements, not replaces, deploy:edge:init (first-boot scaffolding of the bootstrap config and
 * env). It is a push-mode concern; without an SSH transport it is not constructed and the step is a
 * skip.
 */
final class EdgeServiceReconciler
{
    public function __construct(
        private readonly SshTransportInterface $transport,
        private readonly EdgeConfigGenerator $generator,
        private readonly EdgeBaseConfigResolver $resolver,
        private readonly string $edgeDir = '/opt/vortos/edge',
        private readonly string $composeFileName = 'docker-compose.edge.yml',
        private readonly string $projectName = 'vortos',
        private readonly string $adaptImage = 'caddy:2-alpine',
        private readonly ?string $baseConfigPath = null,
    ) {}

    public function reconcile(?string $domain): ReconcileEdgeOutcome
    {
        $composeYaml = $this->generator->generateEdgeComposeYaml((string) ($domain ?? ''));

        // The base config bytes are part of the desired state: a changed Caddyfile must re-converge the
        // edge even if the compose is identical. Resolver is fail-closed, so a configured-but-broken
        // path surfaces here (before we touch the box).
        $base = $this->resolver->resolve($this->baseConfigPath);
        $desiredHash = hash('sha256', implode("\0", [
            $composeYaml,
            $this->adaptImage,
            $base?->sha256 ?? '',
        ]));

        $markerPath = $this->edgeDir . '/.reconcile-hash';
        if (trim($this->readRemote($markerPath) ?? '') === $desiredHash) {
            return ReconcileEdgeOutcome::unchanged($desiredHash);
        }

        $composePath = $this->edgeDir . '/' . $this->composeFileName;
        $this->deliver($composeYaml, $composePath);

        $this->transport->run(new RemoteCommand([
            'docker', 'compose', '-f', $composePath, '-p', $this->projectName, 'up', '-d', '--remove-orphans',
        ]))->throwOnFailure('edge compose up');

        // Record the converged hash only AFTER a successful up, so a failed converge is retried next
        // deploy rather than being masked by a stale marker.
        $this->deliver($desiredHash, $markerPath, '0640');

        return ReconcileEdgeOutcome::converged($desiredHash);
    }

    private function deliver(string $contents, string $remotePath, string $mode = '0644'): void
    {
        $tmpLocal = tempnam(sys_get_temp_dir(), 'vortos-edge-reconcile-');
        if ($tmpLocal === false) {
            throw new \RuntimeException('Failed to create temp file for edge reconcile.');
        }

        try {
            file_put_contents($tmpLocal, $contents);
            $tmpRemote = $remotePath . '.tmp';
            $this->transport->copy($tmpLocal, $tmpRemote, $mode);
            $this->transport->run(new RemoteCommand(['mv', $tmpRemote, $remotePath]))->throwOnFailure('edge file publish');
        } finally {
            @unlink($tmpLocal);
        }
    }

    private function readRemote(string $path): ?string
    {
        try {
            $result = $this->transport->run(new RemoteCommand(['cat', $path]));
        } catch (\Throwable) {
            return null;
        }

        return $result->exitCode === 0 && $result->stdout !== '' ? $result->stdout : null;
    }
}
