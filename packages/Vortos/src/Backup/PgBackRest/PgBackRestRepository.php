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
    ) {
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
}
