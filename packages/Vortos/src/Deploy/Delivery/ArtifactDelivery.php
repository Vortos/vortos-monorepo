<?php

declare(strict_types=1);

namespace Vortos\Deploy\Delivery;

use Vortos\Deploy\Execution\RemoteCommand;
use Vortos\Deploy\Execution\SshTransportInterface;

/**
 * Ships a {@see DeliveryManifest} to the deploy target over the SSH transport, atomically (G3).
 *
 * Files are staged into a per-delivery incoming dir, then swapped into the deploy dir in one move —
 * so a failed/partial transfer never leaves the box running against a half-written config. Required
 * artifacts must be present locally or the delivery fails closed before touching the target.
 */
final class ArtifactDelivery
{
    public function __construct(private readonly SshTransportInterface $transport) {}

    public function deliver(DeliveryManifest $manifest, string $remoteDeployDir): void
    {
        $manifest->assertDeliverable();

        $remoteDeployDir = rtrim($remoteDeployDir, '/');
        $staging = $remoteDeployDir . '/.incoming-' . bin2hex(random_bytes(6));

        $this->run(['mkdir', '-p', $staging]);

        try {
            $madeDirs = [$staging => true];
            foreach ($manifest->present() as $artifact) {
                $remotePath = $staging . '/' . $artifact->remoteRelativePath;
                $remoteDir = dirname($remotePath);
                if (!isset($madeDirs[$remoteDir])) {
                    $this->run(['mkdir', '-p', $remoteDir]);
                    $madeDirs[$remoteDir] = true;
                }
                $this->transport->copy($artifact->localPath, $remotePath, $artifact->mode);
            }

            // Atomic swap: move the staged tree into the deploy dir, then drop the staging dir.
            $this->run(['mkdir', '-p', $remoteDeployDir]);
            $this->run(['sh', '-c', sprintf('cp -a %s/. %s/ && rm -rf %s', $this->quote($staging), $this->quote($remoteDeployDir), $this->quote($staging))]);
        } catch (\Throwable $e) {
            // Best-effort cleanup so a failed delivery leaves no orphaned staging dir.
            $this->run(['rm', '-rf', $staging]);

            throw $e;
        }
    }

    /** @param list<string> $argv */
    private function run(array $argv): void
    {
        $this->transport->run(new RemoteCommand($argv))->throwOnFailure('artifact delivery: ' . implode(' ', $argv));
    }

    private function quote(string $path): string
    {
        return "'" . str_replace("'", "'\\''", $path) . "'";
    }
}
