<?php

declare(strict_types=1);

namespace Vortos\Backup\Service;

use RuntimeException;

/**
 * A single-flight guard so two scheduled backups of the same scope can never run
 * concurrently (which would double-load the source and race on store keys).
 *
 * Backed by an advisory `flock` on a per-scope lock file — non-blocking: if the lock
 * is already held, {@see withLock()} skips the body and returns null, so a pile-up
 * degrades to a no-op rather than overlapping dumps.
 */
final class BackupLock
{
    public function __construct(private readonly string $lockDir)
    {
    }

    /**
     * Run $body while holding the lock for $scope. Returns the body's result, or null
     * if the lock could not be acquired (another backup of this scope is in progress).
     *
     * @template T
     * @param callable():T $body
     * @return T|null
     */
    public function withLock(string $scope, callable $body): mixed
    {
        if (!is_dir($this->lockDir) && !@mkdir($this->lockDir, 0o700, true) && !is_dir($this->lockDir)) {
            throw new RuntimeException("Cannot create backup lock directory: {$this->lockDir}");
        }

        $path = $this->lockDir . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $scope) . '.lock';
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException("Cannot open backup lock file: {$path}");
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return null; // already locked → skip (no overlap)
            }

            try {
                return $body();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
