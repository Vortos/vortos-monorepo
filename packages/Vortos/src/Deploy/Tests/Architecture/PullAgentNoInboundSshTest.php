<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PullAgentNoInboundSshTest extends TestCase
{
    public function test_pull_agent_namespace_never_references_ssh_transport(): void
    {
        $pullAgentDir = dirname(__DIR__, 2) . '/PullAgent';
        $pullAgentCredFile = dirname(__DIR__, 2) . '/Credential/PullAgentCredentialProvider.php';

        $violations = [];

        foreach ($this->phpFiles($pullAgentDir) as $file) {
            $code = (string) file_get_contents($file);
            $basename = basename($file);

            if (str_contains($code, 'SshTransportInterface')) {
                $violations[] = "{$basename} references SshTransportInterface";
            }

            if (str_contains($code, 'Vortos\\Deploy\\Execution')) {
                $violations[] = "{$basename} references Vortos\\Deploy\\Execution namespace";
            }

            if (str_contains($code, 'SshConnectionConfig')) {
                $violations[] = "{$basename} references SshConnectionConfig";
            }
        }

        if (file_exists($pullAgentCredFile)) {
            $code = (string) file_get_contents($pullAgentCredFile);
            if (str_contains($code, 'SshTransportInterface')) {
                $violations[] = 'PullAgentCredentialProvider references SshTransportInterface';
            }
            if (str_contains($code, 'Vortos\\Deploy\\Execution')) {
                $violations[] = 'PullAgentCredentialProvider references Execution namespace';
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_oci_manifest_drivers_do_not_use_ssh(): void
    {
        $ociDir = dirname(__DIR__, 2) . '/Driver/Oci';
        $violations = [];

        foreach ($this->phpFiles($ociDir) as $file) {
            $code = (string) file_get_contents($file);
            $basename = basename($file);

            if (!str_contains($basename, 'Manifest')) {
                continue;
            }

            if (str_contains($code, 'SshTransportInterface') || str_contains($code, 'SshConnectionConfig')) {
                $violations[] = "{$basename} references SSH — manifest source/publisher must not use SSH";
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        if (!is_dir($dir)) {
            return $files;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
