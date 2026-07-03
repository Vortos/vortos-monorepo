<?php

declare(strict_types=1);

namespace Vortos\Deploy\Execution;

/**
 * The SshTransportInterface bound in DI for the push delivery model. It defers building
 * the real {@see ProcessSshTransport} until first use, resolving the active connection
 * from {@see DeployConnectionContext} — which the runner populates once per deploy. This
 * keeps the three consumers (StepExecutor, MountedConfigWriter, SupervisorWorkerController)
 * statically wired while the connection is genuinely runtime.
 */
final class LazySshTransport implements SshTransportInterface
{
    private ?ProcessSshTransport $delegate = null;
    private ?SshConnectionConfig $builtFor = null;

    public function __construct(
        private readonly CommandRunnerInterface $runner,
        private readonly DeployConnectionContext $context,
    ) {}

    public function run(RemoteCommand $command): CommandResult
    {
        return $this->delegate()->run($command);
    }

    public function copy(string $localPath, string $remotePath, string $mode = '0644'): void
    {
        $this->delegate()->copy($localPath, $remotePath, $mode);
    }

    public function openLocalForward(int $remotePort): int
    {
        return $this->delegate()->openLocalForward($remotePort);
    }

    public function closeLocalForward(int $localPort, int $remotePort): void
    {
        $this->delegate()->closeLocalForward($localPort, $remotePort);
    }

    private function delegate(): ProcessSshTransport
    {
        $config = $this->context->config();

        // Rebuild if the active connection changed (idempotent re-runs may re-activate).
        if ($this->delegate === null || $this->builtFor !== $config) {
            $this->delegate = new ProcessSshTransport($this->runner, $config);
            $this->builtFor = $config;
        }

        return $this->delegate;
    }
}
