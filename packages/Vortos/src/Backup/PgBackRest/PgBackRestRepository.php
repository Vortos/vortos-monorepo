<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

use InvalidArgumentException;

/**
 * Where pgBackRest runs and how the framework reaches it (config/backup.php ->pgBackRest()).
 *
 * WHY THE DATABASE CONTAINER. pgBackRest copies the data directory and is PostgreSQL's archive_command,
 * so it must run beside the cluster; the backup node drives it there through the Docker API proxy and
 * never holds the repository credential. The credential reaches only two places: the database container
 * (its env_file) and a drill container ({@see $repositoryEnvFile}, bind-mounted read-only).
 *
 * The repository itself — bucket, cipher, retention, compression — is pgBackRest's own configuration,
 * baked into the database image. Nothing here duplicates it.
 */
final readonly class PgBackRestRepository
{
    public function __construct(
        public string $stanza,
        /** The compose service whose single running container holds the cluster. */
        public string $databaseService,
        /** Host path of the env file holding the repository credential, as the deploy writes it. */
        public string $repositoryEnvFile,
        /** Upper bound for one backup or verify; a run past it is reported failed, never assumed done. */
        public int $commandTimeoutSeconds = 21_600,
        /** The immutable second copy at another provider, or null for a single repository. */
        public ?PgBackRestOffsite $offsite = null,
        /**
         * Server settings the restore drill starts its disposable server with, on top of the image's
         * configuration file (`-c name=value`). For what production needs and a throwaway server
         * must not: TLS whose key exists only in production's tmpfs, an hba file that includes it.
         * Names and values are refused unless plain, because they reach a shell.
         *
         * @var array<string, string>
         */
        public array $drillServerSettings = [],
    ) {
        foreach ($drillServerSettings as $name => $value) {
            if (preg_match('/^[a-z_][a-z0-9_.]*$/', (string) $name) !== 1 || preg_match('#^[A-Za-z0-9_./:=-]*$#', (string) $value) !== 1) {
                throw new InvalidArgumentException(sprintf('pgBackRest drill server setting "%s" must be a plain name and value.', (string) $name));
            }
            if ($name === 'archive_mode') {
                throw new InvalidArgumentException('The drill always starts with archive_mode=off; it is not a setting to override.');
            }
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $stanza) !== 1) {
            throw new InvalidArgumentException(sprintf('pgBackRest stanza must be a plain name, got "%s".', $stanza));
        }
        if (preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $databaseService) !== 1) {
            throw new InvalidArgumentException(sprintf('pgBackRest database service must be a compose service name, got "%s".', $databaseService));
        }
        if (!str_starts_with($repositoryEnvFile, '/') || str_contains($repositoryEnvFile, '..') || str_contains($repositoryEnvFile, ':')) {
            throw new InvalidArgumentException(sprintf('pgBackRest repository env file must be an absolute host path, got "%s".', $repositoryEnvFile));
        }
        if ($commandTimeoutSeconds < 60) {
            throw new InvalidArgumentException('pgBackRest command timeout must be at least 60 seconds.');
        }
    }

    /**
     * The `--repo` argument a command needs to act on one repository. None for a single repository:
     * pgBackRest refuses nothing there and the commands stay exactly what they always were. With an
     * off-site copy declared every command names its repository, because unnamed ones mean "all" for
     * some commands (info, check) and "repo1" for others (backup) — and a probe reading both copies as
     * one would let a healthy primary hide a stale off-site copy.
     *
     * @return list<string>
     */
    public function repoArgument(PgBackRestRepositoryRole $role): array
    {
        if ($role === PgBackRestRepositoryRole::Offsite) {
            if ($this->offsite === null) {
                throw new \LogicException('No off-site pgBackRest repository is declared (->pgBackRest(offsite: ...)).');
            }

            return ['--repo=' . $this->offsite->repo];
        }

        return $this->offsite === null ? [] : ['--repo=1'];
    }
}
