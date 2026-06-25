<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class CredentialNoStandingSecretTest extends TestCase
{
    public function test_no_credential_material_as_class_property(): void
    {
        $credentialDir = dirname(__DIR__, 2) . '/Credential';
        $violations = [];

        foreach ($this->phpFiles($credentialDir) as $file) {
            $code = (string) file_get_contents($file);
            $basename = basename($file);

            if (str_contains($basename, 'Interface') || str_contains($basename, 'Test')) {
                continue;
            }

            if (preg_match('/private\s+(readonly\s+)?string\s+\$privateKey/', $code)) {
                $violations[] = "{$basename} stores private key as a class property";
            }

            if (preg_match('/file_put_contents|fwrite/', $code) && !str_contains($basename, 'Test')) {
                $violations[] = "{$basename} writes to a file — credential material must never be persisted to disk";
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_ephemeral_key_pair_factory_does_not_write_to_disk(): void
    {
        $factoryFile = dirname(__DIR__, 2) . '/Credential/EphemeralKeyPairFactory.php';
        $code = (string) file_get_contents($factoryFile);

        $this->assertStringNotContainsString('file_put_contents', $code);
        $this->assertStringNotContainsString('fwrite', $code);
        $this->assertStringNotContainsString('fopen', $code);
        $this->assertStringNotContainsString('ssh-keygen', $code);
        $this->assertStringNotContainsString('tempnam', $code);
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
            if ($file->getExtension() === 'php' && !str_contains($file->getPathname(), '/Tests/')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
