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

    /**
     * @param string|null $appImage the deployed app image reference (the edge-init service that
     *   hydrates the boot config runs on the app image; the generated compose interpolates it from
     *   $VORTOS_APP_IMAGE, which Docker Compose reads from the process environment / the compose-dir
     *   dot-env file, NOT from a service env_file). It is REQUIRED whenever the edge is actually (re)upped;
     *   without it the compose interpolation fails closed and the deploy aborts before the cutover.
     */
    public function reconcile(?string $domain, ?string $appImage = null): ReconcileEdgeOutcome
    {
        $composeYaml = $this->generator->generateEdgeComposeYaml((string) ($domain ?? ''));

        // The base config bytes are part of the desired state: a changed Caddyfile must re-converge the
        // edge even if the compose is identical. Resolver is fail-closed, so a configured-but-broken
        // path surfaces here (before we touch the box).
        $base = $this->resolver->resolve($this->baseConfigPath);
        // NOTE: the app image is deliberately NOT part of the desired-state hash — edge-init only
        // hydrates the boot config (any recent app image does), so a routine app release must not bounce
        // the edge. It is threaded in only for the up that a compose/base-config change already forces.
        $desiredHash = hash('sha256', implode("\0", [
            $composeYaml,
            $this->adaptImage,
            $base?->sha256 ?? '',
        ]));

        $markerPath = $this->edgeDir . '/.reconcile-hash';
        if (trim($this->readRemote($markerPath) ?? '') === $desiredHash) {
            return ReconcileEdgeOutcome::unchanged($desiredHash);
        }

        // Fail closed with a named cause rather than letting Compose emit its cryptic
        // "set VORTOS_APP_IMAGE" interpolation error from inside the up.
        if ($appImage === null || $appImage === '') {
            throw new \RuntimeException(
                'Edge reconcile requires the deployed app image (VORTOS_APP_IMAGE) to hydrate the '
                . 'edge boot config, but none was provided.',
            );
        }

        $composePath = $this->edgeDir . '/' . $this->composeFileName;
        $this->deliver($composeYaml, $composePath);

        // Prefix an "env VORTOS_APP_IMAGE=<ref>" so Compose interpolates the edge-init image from the
        // process environment. env(1) is a standard binary, so this is transport-portable (works whether
        // the SSH transport execs argv directly or via a shell) and stateless (no dot-env file to keep).
        //
        // NO --remove-orphans: the edge compose project may be SHARED with unrelated services (a
        // single-box install commonly runs the edge and the durable stack — db/redis/kafka — under the
        // same Compose project). --remove-orphans would tear those "orphans" (everything not in the edge
        // file) down. The reconciler only owns the edge services it declares; it must never remove
        // containers it did not create.
        $this->transport->run(new RemoteCommand([
            'env', 'VORTOS_APP_IMAGE=' . $appImage,
            'docker', 'compose', '-f', $composePath, '-p', $this->projectName, 'up', '-d',
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
