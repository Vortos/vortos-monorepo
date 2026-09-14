<?php

declare(strict_types=1);

namespace Vortos\Deploy\Topology;

use RuntimeException;

/**
 * Converges the host copies of the paths a topology bind-mounts onto the versions inside the release
 * image (RC-3).
 *
 * WHY. {@see ComposeTopologySync} wrote the compose file and nothing it pointed at, so every relative
 * bind mount resolved against a host copy nobody updated. The Postgres init directory on the box was
 * frozen for two months and missing the scripts that grant replication access; a fresh volume would
 * have booted a primary no backup could stream from. The files are already inside the signed image —
 * the same place the topology is read from — so they inherit the same supply-chain guarantee and need
 * no second transfer channel.
 *
 * Runs as root on the host, so it trusts nothing it is pointed at: every path is checked for symlinks
 * on both sides (a link in the image or on the host could redirect a root write anywhere), only regular
 * files are copied, content is compared by sha256, and every write is a same-directory rename so no
 * reader ever sees a file half written. A directory is converged exactly — a stale file the image no
 * longer ships is removed, and the directory's own mode and owner are held too, because for an init
 * directory an extra script, or a directory someone else may write into, is an extra thing that runs.
 * Anything replaced or removed is copied first into a root-only backup directory.
 */
final class HostPathSync
{
    public const BACKUP_DIR = '.vortos-synced-backup';

    public function __construct(
        private readonly string $imageRoot,
        private readonly string $hostRoot,
    ) {
        foreach ([$imageRoot, $hostRoot] as $root) {
            if (!str_starts_with($root, '/') || !is_dir($root) || is_link($root)) {
                throw new RuntimeException(sprintf('Sync root %s must be an existing absolute directory, not a link — refusing.', $root));
            }
        }
    }

    /**
     * @param list<SyncedPathSpec> $specs
     *
     * @return list<SyncedPathResult>
     */
    public function sync(array $specs, bool $apply): array
    {
        // Plan everything before writing anything: a refusal on the third path must not leave the
        // first two half converged.
        $plans = array_map(fn (SyncedPathSpec $spec): array => $this->plan($spec), $specs);

        $stamp = date('Ymd-His');
        $results = [];
        foreach ($plans as [$spec, $isFile, $existed, $changed, $removed, $directoryDrift]) {
            $drifted = $changed !== [] || $removed !== [] || $directoryDrift;

            $backupDir = null;
            if ($apply && $drifted) {
                $backupDir = $this->apply($spec, $isFile, $changed, $removed, $stamp);
            }

            $status = !$existed ? SyncedPathStatus::Installed
                : ($drifted ? SyncedPathStatus::Updated : SyncedPathStatus::InSync);

            $results[] = new SyncedPathResult(
                path: $spec->path,
                status: $status,
                applied: $apply && $drifted,
                isFile: $isFile,
                changedFiles: array_keys($changed),
                removedFiles: $removed,
                services: $spec->services,
                backupDir: $backupDir,
            );
        }

        return $results;
    }

    /**
     * @return array{SyncedPathSpec, bool, bool, array<string, array{source: string, mode: int}>, list<string>, bool}
     *         [spec, is a single file, host path existed, files to write (relative => source + mode),
     *          host files to remove, the synced directory's own mode or owner differs]
     */
    private function plan(SyncedPathSpec $spec): array
    {
        $source = $this->imageRoot . '/' . $spec->path;
        $this->assertNoLinks($this->imageRoot, $spec->path, 'release image');

        if (!is_file($source) && !is_dir($source)) {
            throw new RuntimeException(sprintf(
                'Synced path %s is not in the release image — refusing. It is declared in config/pipeline.php, so the build has changed shape and this must be fixed rather than skipped.',
                $spec->path,
            ));
        }

        $isFile = is_file($source);
        $desired = [];
        foreach ($isFile ? [$spec->path] : $this->filesUnder($this->imageRoot, $spec->path, 'release image') as $relative) {
            $desired[$relative] = [
                'source' => $this->imageRoot . '/' . $relative,
                'mode' => $spec->fileMode((fileperms($this->imageRoot . '/' . $relative) & 0o100) !== 0),
            ];
        }

        $this->assertNoLinks($this->hostRoot, $spec->path, 'host');
        $target = $this->hostRoot . '/' . $spec->path;
        $existed = file_exists($target);

        if ($existed && is_file($target) !== $isFile) {
            throw new RuntimeException(sprintf('Synced path %s is a %s in the image but not on the host — refusing to replace one with the other.', $spec->path, $isFile ? 'file' : 'directory'));
        }

        $changed = [];
        foreach ($desired as $relative => $file) {
            $host = $this->hostRoot . '/' . $relative;
            if (!is_file($host)
                || hash_file('sha256', $host) !== hash_file('sha256', $file['source'])
                || (fileperms($host) & 0o7777) !== $file['mode']
                || fileowner($host) !== $spec->uid
                || filegroup($host) !== $spec->gid) {
                $changed[$relative] = $file;
            }
        }

        $removed = [];
        $directoryDrift = false;
        if ($existed && !$isFile) {
            $removed = array_values(array_diff($this->filesUnder($this->hostRoot, $spec->path, 'host'), array_keys($desired)));
            $directoryDrift = (fileperms($target) & 0o7777) !== $spec->directoryMode()
                || fileowner($target) !== $spec->uid
                || filegroup($target) !== $spec->gid;
        }

        return [$spec, $isFile, $existed, $changed, $removed, $directoryDrift];
    }

    /**
     * @param array<string, array{source: string, mode: int}> $changed
     * @param list<string>                                    $removed
     */
    private function apply(SyncedPathSpec $spec, bool $isFile, array $changed, array $removed, string $stamp): ?string
    {
        $backupDir = null;
        $backup = function (string $relative) use (&$backupDir, $stamp): void {
            $host = $this->hostRoot . '/' . $relative;
            if (!is_file($host)) {
                return;
            }
            $backupDir ??= $this->hostRoot . '/' . self::BACKUP_DIR . '/' . $stamp;
            $copy = $backupDir . '/' . $relative;
            $this->ensureDir(\dirname($copy), 0o700, 0, 0, strict: false);
            if (!@copy($host, $copy) || !@chmod($copy, 0o600)) {
                throw new RuntimeException(sprintf('Could not back up %s before replacing it — refusing to overwrite without a rollback point.', $relative));
            }
        };

        if (!$isFile) {
            $this->ensureDir($this->hostRoot . '/' . $spec->path, $spec->directoryMode(), $spec->uid, $spec->gid, strict: true);
        }

        foreach ($changed as $relative => $file) {
            $backup($relative);

            $host = $this->hostRoot . '/' . $relative;
            $directory = \dirname($host);
            if ($directory !== $this->hostRoot) {
                // Inside a synced directory, every subdirectory carries the declared owner; above a
                // single synced file, the parents are plain root-owned traversal directories.
                $this->ensureDir(
                    $directory,
                    $isFile ? 0o755 : $spec->directoryMode(),
                    $isFile ? 0 : $spec->uid,
                    $isFile ? 0 : $spec->gid,
                    strict: !$isFile,
                );
            }

            $staged = $host . '.incoming';
            if (!@copy($file['source'], $staged)) {
                throw new RuntimeException(sprintf('Could not stage %s on the host.', $relative));
            }

            // Mode and owner are set on the staged file BEFORE the rename, so the live path never
            // exists with the wrong permissions — not even for a moment a reader could hit.
            if (!@chmod($staged, $file['mode'])
                || ((fileowner($staged) !== $spec->uid || filegroup($staged) !== $spec->gid)
                    && (!@chown($staged, $spec->uid) || !@chgrp($staged, $spec->gid)))) {
                @unlink($staged);

                throw new RuntimeException(sprintf('Could not give %s mode %04o and owner %d:%d — refusing to install it with other permissions.', $relative, $file['mode'], $spec->uid, $spec->gid));
            }

            if (!@rename($staged, $host)) {
                @unlink($staged);

                throw new RuntimeException(sprintf('Could not move %s into place.', $relative));
            }
        }

        foreach ($removed as $relative) {
            $backup($relative);
            if (!@unlink($this->hostRoot . '/' . $relative)) {
                throw new RuntimeException(sprintf('Could not remove %s, which the release no longer ships.', $relative));
            }
        }

        clearstatcache();

        return $backupDir;
    }

    private function ensureDir(string $directory, int $mode, int $uid, int $gid, bool $strict): void
    {
        if (!is_dir($directory) && !@mkdir($directory, $mode, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create %s.', $directory));
        }

        if (!$strict) {
            return;
        }

        clearstatcache(true, $directory);
        if (!@chmod($directory, $mode)
            || ((fileowner($directory) !== $uid || filegroup($directory) !== $gid) && (!@chown($directory, $uid) || !@chgrp($directory, $gid)))) {
            throw new RuntimeException(sprintf('Could not give %s mode %04o and owner %d:%d.', $directory, $mode, $uid, $gid));
        }
    }

    /** Every component from the root down to $relative must be a real file or directory, never a link. */
    private function assertNoLinks(string $root, string $relative, string $side): void
    {
        $path = $root;
        foreach (explode('/', $relative) as $segment) {
            $path .= '/' . $segment;
            if (is_link($path)) {
                throw new RuntimeException(sprintf('%s in the %s is a symbolic link — refusing to follow it as root.', substr($path, \strlen($root) + 1), $side));
            }
        }
    }

    /** @return list<string> project-relative regular files under a directory, sorted; refuses links and special files */
    private function filesUnder(string $root, string $relative, string $side): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/' . $relative, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            $path = substr($entry->getPathname(), \strlen($root) + 1);
            if ($entry->isLink()) {
                throw new RuntimeException(sprintf('%s in the %s is a symbolic link — refusing to follow it as root.', $path, $side));
            }
            if ($entry->isDir()) {
                continue;
            }
            if (!$entry->isFile()) {
                throw new RuntimeException(sprintf('%s in the %s is not a regular file — refusing.', $path, $side));
            }
            if (str_ends_with($path, '.incoming')) {
                continue;
            }
            $files[] = $path;
        }

        sort($files);

        return $files;
    }
}
