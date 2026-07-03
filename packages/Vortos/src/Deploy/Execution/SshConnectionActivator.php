<?php

declare(strict_types=1);

namespace Vortos\Deploy\Execution;

use Vortos\Deploy\Credential\CredentialProviderRegistry;
use Vortos\Deploy\Credential\CredentialUse;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\EnvironmentName;

/**
 * Resolves and activates the SSH connection for one push-model deploy, then tears it
 * down — the runtime glue between the credential provider and the transport.
 *
 * The credential lease materializes short-lived files (identity, known_hosts) that are
 * unlinked when the lease is wiped, so the deploy's mutating work must run *inside* the
 * lease scope. {@see withConnection()} does exactly that: it leases the credential, joins
 * it with the static {@see SshConnectionSettings} into a {@see SshConnectionConfig},
 * activates it on the shared {@see DeployConnectionContext} that {@see LazySshTransport}
 * reads, runs the caller's work, and guarantees deactivation + credential wipe afterwards.
 */
final class SshConnectionActivator
{
    public function __construct(
        private readonly CredentialProviderRegistry $credentials,
        private readonly DeployConnectionContext $context,
        private readonly SshConnectionSettings $settings,
    ) {}

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function withConnection(DeploymentDefinition $definition, EnvironmentName $env, callable $work): mixed
    {
        $provider = $this->credentials->provider($definition->credential);
        $lease = $provider->lease($env);

        return $lease->use(function (CredentialUse $use) use ($work): mixed {
            $knownHostsMaterial = $use->knownHostsMaterial()
                ?? throw new \RuntimeException(
                    'No known_hosts material was provided; strict host-key verification is mandatory and there is '
                    . 'no trust-on-first-use fallback. Provision the deploy_known_hosts secret.'
                );

            // Materialize the identity + known_hosts to short-lived 0600/0644 files under the
            // Execution layer (not the credential layer), then unlink them in the finally —
            // no key survives the lease. ssh authenticates via -i on this file.
            $ephemeral = [];
            $identityPath = $this->materialize($use->identityMaterial()->reveal(), 0o600, $ephemeral);
            $knownHostsPath = $this->materialize($knownHostsMaterial->reveal(), 0o644, $ephemeral);
            $controlPath = sys_get_temp_dir() . '/vcm-' . bin2hex(random_bytes(6));

            $config = $this->settings->toConnectionConfig($identityPath, $knownHostsPath, $controlPath);
            $this->context->activate($config);

            try {
                return $work();
            } finally {
                $this->context->deactivate();
                foreach ([...$ephemeral, $controlPath] as $path) {
                    if (file_exists($path)) {
                        @unlink($path);
                    }
                }
            }
        });
    }

    /**
     * @param list<string> $ephemeral collects the created path for teardown
     */
    private function materialize(string $contents, int $mode, array &$ephemeral): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vortos-ssh-');
        if ($path === false) {
            throw new \RuntimeException('Could not allocate a temp file for the SSH connection.');
        }

        $ephemeral[] = $path;
        chmod($path, $mode);
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('Could not materialize the SSH connection file.');
        }
        chmod($path, $mode);

        return $path;
    }
}
