<?php

declare(strict_types=1);

namespace Vortos\Deploy\Topology;

/**
 * One path the deploy copies from the release image onto the host, as the generated deploy script
 * hands it to vortos:deploy:compose:sync (RC-3): "<path>@<mode>@<uid>:<gid>@<service>[,<service>…]".
 *
 * Parsed and validated here rather than trusted, because this runs as root on the host: a path that
 * escaped the image root or the deploy dir, or a mode that granted group or world write, must never
 * reach the filesystem code — whatever produced the argument.
 */
final readonly class SyncedPathSpec
{
    /** @param non-empty-list<string> $services */
    private function __construct(
        public string $path,
        public int $mode,
        public int $uid,
        public int $gid,
        public array $services,
    ) {}

    public static function parse(string $spec): self
    {
        $parts = explode('@', $spec);
        if (\count($parts) !== 4) {
            throw new \InvalidArgumentException(sprintf('Synced path spec must be <path>@<mode>@<uid>:<gid>@<services>, got "%s".', $spec));
        }

        [$path, $mode, $owner, $services] = $parts;

        if (\strlen($path) > 255
            || preg_match('#^[A-Za-z0-9_][A-Za-z0-9_.-]*(/[A-Za-z0-9_][A-Za-z0-9_.-]*)*$#', $path) !== 1
            || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1) {
            throw new \InvalidArgumentException(sprintf('Synced path "%s" must be relative, non-hidden and without traversal.', $path));
        }

        if (!\in_array($mode, ['0644', '0640', '0600'], true)) {
            throw new \InvalidArgumentException(sprintf('Synced path "%s" mode must be one of 0644, 0640, 0600, got "%s".', $path, $mode));
        }

        if (preg_match('/^(\d{1,10}):(\d{1,10})$/', $owner, $ids) !== 1) {
            throw new \InvalidArgumentException(sprintf('Synced path "%s" owner must be numeric <uid>:<gid>, got "%s".', $path, $owner));
        }

        $names = explode(',', $services);
        foreach ($names as $name) {
            if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name) !== 1) {
                throw new \InvalidArgumentException(sprintf('Synced path "%s" names an invalid compose service "%s".', $path, $name));
            }
        }

        return new self($path, (int) octdec($mode), (int) $ids[1], (int) $ids[2], $names);
    }

    /**
     * The mode a file receives: the declared mode, plus an execute bit for exactly the classes that may
     * read it when the image copy is executable. Nothing else about the image's own mode is trusted.
     */
    public function fileMode(bool $executable): int
    {
        return $executable ? $this->mode | (($this->mode & 0o444) >> 2) : $this->mode;
    }

    /** Directories are traversable by exactly the classes that may read the files in them. */
    public function directoryMode(): int
    {
        return $this->fileMode(true);
    }
}
