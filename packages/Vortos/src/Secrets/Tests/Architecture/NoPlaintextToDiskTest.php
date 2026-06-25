<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Zero plaintext to disk (§15.2): the only class in the package that writes
 * secret material to a filesystem path is {@see \Vortos\Secrets\Driver\File\FileSecretStore},
 * and it must persist nothing but the already-encrypted {@see \Vortos\Secrets\Crypto\SecretEnvelope}
 * — never a raw call to `reveal()`, and never any other plaintext-shaped local
 * variable threaded straight into `file_put_contents()`.
 */
final class NoPlaintextToDiskTest extends TestCase
{
    public function test_file_secret_store_never_reveals(): void
    {
        $code = $this->fileSecretStoreSource();

        $this->assertStringNotContainsString(
            '->reveal()',
            $code,
            'FileSecretStore must never call SecretValue::reveal() — it may only persist an already-encrypted SecretEnvelope.',
        );
    }

    public function test_file_secret_store_writes_only_the_envelope(): void
    {
        $code = $this->fileSecretStoreSource();

        $this->assertStringContainsString(
            'file_put_contents',
            $code,
            'Expected FileSecretStore to persist via file_put_contents — update this test if the write mechanism changes.',
        );

        $this->assertStringContainsString(
            '$envelope->toArray()',
            $code,
            'FileSecretStore must serialize the SecretEnvelope (whose toArray() base64-encodes every binary field) — not a bespoke plaintext structure.',
        );
    }

    public function test_no_other_class_writes_secret_material_to_a_filesystem_path(): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];

        $writeFunctions = ['file_put_contents', 'fwrite', 'fputs'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($root) + 1);

            if (str_starts_with($relativePath, 'Tests/') || $relativePath === 'Driver/File/FileSecretStore.php') {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());
            foreach ($writeFunctions as $function) {
                if (str_contains($code, $function . '(')) {
                    $violations[] = "{$relativePath} calls {$function}()";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Only FileSecretStore may write to the filesystem:\n  - " . implode("\n  - ", $violations),
        );
    }

    private function fileSecretStoreSource(): string
    {
        $path = dirname(__DIR__, 2) . '/Driver/File/FileSecretStore.php';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
