<?php

declare(strict_types=1);

namespace Vortos\Deploy\Runtime;

/**
 * Writes file-shaped secrets (G8) to their tmpfs host paths so the color can bind-mount them.
 *
 * Security invariants:
 *  - Host paths are validated by {@see FileSecret} to be on a tmpfs (RAM) mount — plaintext never
 *    touches persistent storage.
 *  - Each file is created with the declared owner-read-only mode (0400 by default) via an atomic
 *    write (temp + chmod + rename) so a partially-written or world-readable window never exists.
 *  - {@see wipe()} overwrites each materialised file with zeros before unlinking, and the whole tree
 *    is RAM-backed, so a teardown leaves no recoverable plaintext.
 *
 * The plaintext is supplied by a reader callback (kept out of this class so it stays decoupled from
 * vortos-secrets and trivially testable); the caller is responsible for wiping the source SecretValue.
 */
final class FileSecretMaterializer
{
    /** @var list<string> paths written this run, for {@see wipe()}. */
    private array $written = [];

    /**
     * @param iterable<FileSecret>              $fileSecrets
     * @param callable(FileSecret): string      $reader returns the plaintext for a secret
     *
     * @return list<string> the container paths that were satisfied
     */
    public function materialize(iterable $fileSecrets, callable $reader): array
    {
        $satisfied = [];

        foreach ($fileSecrets as $fileSecret) {
            $dir = $fileSecret->hostDirectory();
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Cannot create tmpfs secret directory "%s".', $dir));
            }
            @chmod($dir, 0700);

            $plaintext = $reader($fileSecret);

            // Atomic, never-world-readable write: temp file with the target mode, then rename.
            $tmp = $fileSecret->hostPath . '.tmp-' . bin2hex(random_bytes(6));
            if (file_put_contents($tmp, $plaintext) === false) {
                throw new \RuntimeException(sprintf('Cannot write file secret "%s".', $fileSecret->name));
            }
            @chmod($tmp, $fileSecret->mode);
            if (!@rename($tmp, $fileSecret->hostPath)) {
                @unlink($tmp);
                throw new \RuntimeException(sprintf('Cannot place file secret "%s".', $fileSecret->name));
            }

            $this->written[] = $fileSecret->hostPath;
            $satisfied[] = $fileSecret->containerPath;
        }

        return $satisfied;
    }

    /** Overwrite and remove every file written this run. Idempotent. */
    public function wipe(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                $size = filesize($path);
                if ($size !== false && $size > 0) {
                    @file_put_contents($path, str_repeat("\0", $size));
                }
                @unlink($path);
            }
        }

        $this->written = [];
    }
}
