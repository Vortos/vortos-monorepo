<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class CredentialDriverAgnosticismTest extends TestCase
{
    private const PROVIDER_STRINGS = [
        'ACTIONS_ID_TOKEN_REQUEST_URL',
        'ACTIONS_ID_TOKEN_REQUEST_TOKEN',
        'ACTIONS_',
        'github.com',
    ];

    public function test_provider_strings_only_in_driver_namespace(): void
    {
        $deployDir = dirname(__DIR__, 2);
        $violations = [];

        foreach ($this->phpFiles($deployDir) as $file) {
            if (str_contains($file, '/Driver/')) {
                continue;
            }
            if (str_contains($file, '/Tests/')) {
                continue;
            }

            $code = (string) file_get_contents($file);
            $basename = basename($file);

            foreach (self::PROVIDER_STRINGS as $providerString) {
                if (str_contains($code, $providerString)) {
                    $violations[] = "{$basename} contains '{$providerString}' outside Driver/ namespace";
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_credential_ports_do_not_mention_github(): void
    {
        $credentialDir = dirname(__DIR__, 2) . '/Credential';
        $violations = [];

        foreach ($this->phpFiles($credentialDir) as $file) {
            $code = (string) file_get_contents($file);
            $basename = basename($file);

            if (str_contains($file, '/Tests/') || str_contains($file, '/Governance/')) {
                continue;
            }

            if (preg_match('/\bgithub\b/i', $code) && !str_contains($code, '* ')) {
                $violations[] = "{$basename} mentions 'github' in functional code";
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_pull_agent_core_does_not_mention_oci_driver(): void
    {
        $pullAgentDir = dirname(__DIR__, 2) . '/PullAgent';
        $violations = [];

        foreach ($this->phpFiles($pullAgentDir) as $file) {
            $code = (string) file_get_contents($file);
            $basename = basename($file);

            if (str_contains($code, 'Vortos\\Deploy\\Driver\\Oci')) {
                $violations[] = "{$basename} imports OCI driver namespace — ports must stay agnostic";
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
