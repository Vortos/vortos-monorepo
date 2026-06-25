<?php

declare(strict_types=1);

namespace Vortos\Secrets\Driver\File;

use RuntimeException;
use Vortos\Secrets\Crypto\SecretEnvelope;
use Vortos\Secrets\Exception\SecretNotFoundException;
use Vortos\Secrets\Store\SecretStoreInterface;

/**
 * Persists one {@see SecretEnvelope} as JSON at a single mounted path. **Only the
 * ciphertext envelope is ever written** — the envelope's own `toArray()` already
 * base64-encodes every binary field, so the on-disk JSON contains no raw secret
 * bytes by construction (§15.2, zero plaintext to disk).
 *
 * Writes are atomic (write to a sibling temp file, then `rename()`) so a crash
 * mid-write can never leave a half-written, corrupt envelope on disk.
 */
final class FileSecretStore implements SecretStoreInterface
{
    public function __construct(private readonly string $path) {}

    public function load(): SecretEnvelope
    {
        if (!$this->exists()) {
            throw new SecretNotFoundException('No secret store found at: ' . $this->path);
        }

        $contents = file_get_contents($this->path);
        if ($contents === false) {
            throw new RuntimeException('Failed to read secret store at: ' . $this->path);
        }

        /** @var array{schemaVersion: int, aeadCiphertext: string, nonce: string, aad: string, wrappedDeks: array<string, string>, createdAt: string} $data */
        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return SecretEnvelope::fromArray($data);
    }

    public function save(SecretEnvelope $envelope): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create secret store directory: ' . $directory);
        }

        $json = json_encode($envelope->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        $tempPath = $this->path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($tempPath, $json) === false) {
            throw new RuntimeException('Failed to write secret store temp file: ' . $tempPath);
        }
        chmod($tempPath, 0600);

        if (!rename($tempPath, $this->path)) {
            throw new RuntimeException('Failed to atomically replace secret store at: ' . $this->path);
        }
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }
}
