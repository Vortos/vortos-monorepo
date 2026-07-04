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
        $secretsFlags = $useAgeKek
            ? sprintf(
                '-e VORTOS_AGE_IDENTITY -e VORTOS_SECRETS_STORE_PATH=/app/vortos-secrets.age '
                . '-v %s/vortos-secrets.age:/app/vortos-secrets.age:ro ',
                $deployDir,
            )
            : '';

        $dockerRun = sprintf(
            'docker run --rm --network %s --env-file %s/.env.prod %s%s php bin/console ',
            $network,
            $deployDir,
            $secretsFlags,
            $imageRef,
        );

        $lines = ['set -euo pipefail'];

        if ($useAgeKek) {
            // The age KEK travels inside the encrypted SSH channel and is forwarded into each
            // container via `-e VORTOS_AGE_IDENTITY`. GitHub expands the secret into the run block
            // and masks it in logs; it never touches the runner's argv or disk.
            $lines[] = "export VORTOS_AGE_IDENTITY='\${{ secrets.VORTOS_AGE_IDENTITY }}'";
        }

        // The VPS pulls the image, so it authenticates to the registry there. For GHCR the ephemeral
        // GITHUB_TOKEN is forwarded over the SSH channel (GITHUB_TOKEN is the one credential allowed
        // under the OIDC zero-standing-secret posture); other registries are assumed pre-authenticated
        // on the box (documented prerequisite).
        if ($definition->registryProvider === 'ghcr') {
            $lines[] = "echo '\${{ secrets.GITHUB_TOKEN }}' | docker login ghcr.io -u '\${{ github.actor }}' --password-stdin";
        }

        $lines = [
            ...$lines,
            sprintf('docker pull %s', $imageRef),
            $dockerRun . sprintf(
                'vortos:release:record-manifest --env=%s --repository=%s --digest=%s --git-sha=${{ github.sha }} --arch=%s --builder-id=github-actions',
                $envExpr,
                $repo,
                $digestExpr,
                $arch,
            ),
            $dockerRun . sprintf('vortos:deploy:provision --env=%s --json', $envExpr),
            $dockerRun . sprintf('deploy:doctor --env=%s --json', $envExpr),
            $dockerRun . sprintf(
                'deploy --env=%s --yes --json --image-repository=%s --image-digest=%s',
                $envExpr,
                $repo,
                $digestExpr,
            ),
        ];

        return implode("\n", $lines) . "\n";
    }
}
