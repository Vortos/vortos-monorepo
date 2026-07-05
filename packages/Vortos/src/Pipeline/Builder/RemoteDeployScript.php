<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Builder;

use Vortos\Pipeline\Definition\PipelineDefinition;

/**
 * Generates the shell script that runs the deploy-in-image console commands **on the target VPS**,
 * not on the CI runner (G1 + B9).
 *
 * The runner only opens SSH; the image is pulled and every command executes on the VPS, attached to
 * the app's Docker network. This fixes two things at once:
 *   - **G1** — `vortos:release:record-manifest` and `deploy:doctor` reach the production release
 *     ledger DB (a service on the app network) by construction; there is no infra-less runner and no
 *     tunnel to maintain.
 *   - **B9** — the arm64-native image runs on the arm64 VPS; it is never `docker run` on the amd64
 *     runner, so there is no arch mismatch.
 *
 * On the VPS the commands run in the deploy runtime's local mode (no nested SSH): the connection
 * coordinates are deliberately NOT forwarded into the container.
 */
final class RemoteDeployScript
{
    /**
     * @param string $imageRef   the pinned pull reference, e.g. `ghcr.io/acme/app@${{ needs.build.outputs.image }}`
     * @param string $repo       the image repository, e.g. `ghcr.io/acme/app`
     * @param string $digestExpr the digest expression, e.g. `${{ needs.build.outputs.image }}`
     * @param string $envExpr    the environment expression, e.g. `${{ matrix.environment }}`
     */
    public function build(
        PipelineDefinition $definition,
        string $imageRef,
        string $repo,
        string $digestExpr,
        string $envExpr,
    ): string {
        $arch = $definition->targetArch->value;
        $deployDir = $definition->remoteDeployDir;
        $network = $definition->appNetwork;

        // ssh-key posture: the age KEK is exported into the VPS deploy session (over the encrypted
        // SSH channel) and forwarded into the container, which opens the delivered, age-encrypted
        // store. OIDC posture: zero standing secrets — nothing to forward.
        $useAgeKek = !$definition->oidc;

        $lines = ['set -euo pipefail'];

        if ($useAgeKek) {
            // The age KEK travels inside the encrypted SSH channel and is forwarded into each
            // container via `-e VORTOS_AGE_IDENTITY`. GitHub expands the secret into the run block
            // and masks it in logs; it never touches the runner's argv or disk.
            $lines[] = "export VORTOS_AGE_IDENTITY='\${{ secrets.VORTOS_AGE_IDENTITY }}'";
        }

        // B15: the age store is delivered 0640 (owner+group read) owned by the deploy uid, but the
        // one-shot container runs as the image's uid. Read the store's group on the box and grant it
        // to the container with --group-add so it can group-read the store — least privilege, no
        // world-read. The store is age ciphertext, so this exposes no plaintext.
        $groupAdd = '';
        if ($useAgeKek) {
            $lines[] = sprintf('VORTOS_SECRETS_GID="$(stat -c \'%%g\' %s/vortos-secrets.age)"', $deployDir);
            $groupAdd = '--group-add "$VORTOS_SECRETS_GID" ';
        }

        $secretsFlags = $useAgeKek
            ? sprintf(
                '-e VORTOS_AGE_IDENTITY -e VORTOS_SECRETS_STORE_PATH=/app/vortos-secrets.age '
                . '-v %s/vortos-secrets.age:/app/vortos-secrets.age:ro ',
                $deployDir,
            )
            : '';

        // B19: the blue-green cutover runs `docker compose up` for the color from INSIDE this one-shot.
        // The generated cutover compose sets `env_file:` to the RuntimeServiceSpec env-file paths —
        // host paths that are absent inside the one-shot unless mounted. Bind-mount each declared
        // runtime env-file read-only at its identical absolute path so `docker compose` can resolve it.
        // Deduplicated and rooted-checked at the definition; the color genuinely needs this env to boot.
        $runtimeEnvMounts = '';
        $envFileIndex = 0;
        foreach (array_values(array_unique($definition->runtimeEnvFiles)) as $envFile) {
            $runtimeEnvMounts .= sprintf('-v %s:%s:ro ', $envFile, $envFile);

            // GAP-A: the blue-green cutover runs `docker compose up` for the color from INSIDE this
            // one-shot, which parses `env_file:` at load time as the image's *non-root* uid (e.g.
            // 1000). The provisioned env files are 0640 owned by the deploy user, so uid 1000 gets
            // "permission denied" unless it is in the file's group. Read each env file's gid on the
            // box and grant it to the one-shot with --group-add — least privilege: this exposes
            // *group* read only, never world-read, and the store's gid is added separately above. A
            // redundant numeric gid across files is harmless (supplementary groups are a set).
            $gidVar = sprintf('VORTOS_ENVFILE_GID_%d', $envFileIndex);
            $lines[] = sprintf('%s="$(stat -c \'%%g\' %s)"', $gidVar, $envFile);
            $groupAdd .= sprintf('--group-add "$%s" ', $gidVar);
            $envFileIndex++;
        }

        // G8: file-shaped secrets are materialised by the one-shot into tmpfs host dirs, which the
        // color then bind-mounts read-only. The one-shot mounts each dir read-write so the materialize
        // step can write; the dirs are created (0700) up-front so the docker -v mount target exists.
        $fileSecretDirs = array_values(array_unique($definition->runtimeFileSecretDirs));
        $fileSecretMounts = '';
        foreach ($fileSecretDirs as $dir) {
            $fileSecretMounts .= sprintf('-v %s:%s ', $dir, $dir);
        }

        // B16: the blue-green cutover shells `docker compose`/`docker pull` from inside the one-shot.
        // It reaches Docker ONLY through the least-privilege docker-socket-proxy (never the raw
        // socket), via DOCKER_HOST on the shared app network.
        $dockerRun = sprintf(
            'docker run --rm --network %s -e DOCKER_HOST=tcp://docker-socket-proxy:2375 --env-file %s/.env.prod %s%s%s%s%s php bin/console ',
            $network,
            $deployDir,
            $groupAdd,
            $runtimeEnvMounts,
            $fileSecretMounts,
            $secretsFlags,
            $imageRef,
        );

        // The VPS pulls the image, so it authenticates to the registry there. Provider-driven so any
        // supported registry (ghcr / docker-hub / gcp-artifact) gets a real remote login instead of an
        // unauthenticated pull (G7).
        $loginLine = $this->remoteLoginLine($definition->registryProvider);
        if ($loginLine !== null) {
            $lines[] = $loginLine;
        }

        $lines[] = sprintf('docker pull %s', $imageRef);

        // G8: create the tmpfs secret dirs (0700) so the one-shot's read-write mount target exists.
        foreach ($fileSecretDirs as $dir) {
            $lines[] = sprintf('mkdir -p %s && chmod 700 %s', $dir, $dir);
        }

        // G9: provision runs BEFORE record-manifest. record-manifest writes the release-ledger row,
        // whose schema is created by `vortos:migrate` inside provision — on a fresh DB the ledger
        // table would not exist yet if record-manifest ran first.
        $lines[] = $dockerRun . sprintf('vortos:deploy:provision --env=%s --json', $envExpr);
        $lines[] = $dockerRun . sprintf(
            'vortos:release:record-manifest --env=%s --repository=%s --digest=%s --git-sha=${{ github.sha }} --arch=%s --builder-id=github-actions',
            $envExpr,
            $repo,
            $digestExpr,
            $arch,
        );
        $lines[] = $dockerRun . sprintf('deploy:doctor --env=%s --json', $envExpr);

        // G8: materialize file-shaped secrets to their tmpfs paths right before the cutover, so the
        // color's compose mounts find them. Only emitted when file secrets are declared.
        if ($fileSecretDirs !== []) {
            $lines[] = $dockerRun . sprintf('vortos:deploy:materialize-file-secrets --env=%s --json', $envExpr);
        }

        $lines[] = $dockerRun . sprintf(
            'deploy --env=%s --yes --json --image-repository=%s --image-digest=%s',
            $envExpr,
            $repo,
            $digestExpr,
        );

        return implode("\n", $lines) . "\n";
    }

    /**
     * The remote `docker login` line for the registry provider, or null when none is needed. The
     * credential source per provider mirrors the build-stage login providers; secrets are piped via
     * --password-stdin so they never touch argv or the runner disk.
     */
    private function remoteLoginLine(string $registryProvider): ?string
    {
        return match ($registryProvider) {
            'ghcr' => "echo '\${{ secrets.GITHUB_TOKEN }}' | docker login ghcr.io -u '\${{ github.actor }}' --password-stdin",
            'docker-hub', 'dockerhub' => "echo '\${{ secrets.DOCKER_TOKEN }}' | docker login docker.io -u '\${{ secrets.DOCKER_USERNAME }}' --password-stdin",
            'gcp-artifact', 'gcp-artifact-registry' => "echo '\${{ secrets.GCP_ARTIFACT_TOKEN }}' | docker login '\${{ vars.GCP_ARTIFACT_HOST }}' -u oauth2accesstoken --password-stdin",
            default => null,
        };
    }
}
