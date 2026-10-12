<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

/**
 * The pgBackRest operations the framework drives. Every one fails loudly: a non-zero exit is an
 * exception carrying pgBackRest's own (already redacted) explanation, never a logged warning.
 */
final class PgBackRestClient
{
    private const SHORT_TIMEOUT_SECONDS = 300;

    public function __construct(
        private readonly PgBackRestExecutorInterface $executor,
        private readonly PgBackRestRepository $repository,
    ) {}

    public function repository(): PgBackRestRepository
    {
        return $this->repository;
    }

    public function info(): PgBackRestInfo
    {
        $arguments = ['info', '--output=json'];
        $result = $this->executor->run($arguments, self::SHORT_TIMEOUT_SECONDS);

        if (!$result->succeeded()) {
            throw PgBackRestCommandFailedException::for($arguments, $result);
        }

        return PgBackRestInfo::fromJson($result->stdout, $this->repository->stanza);
    }

    /**
     * Take a backup. pgBackRest expires what its retention no longer needs as the last step of every
     * backup, so there is no separate retention schedule to forget.
     */
    public function backup(PgBackRestBackupType $type): void
    {
        $this->run(['backup', '--type=' . $type->value], $this->repository->commandTimeoutSeconds);
    }

    /** Re-read every file in the repository and check it against the checksum recorded when it was written. */
    public function verify(): void
    {
        $this->run(['verify'], $this->repository->commandTimeoutSeconds);
    }

    /** Prove the server archives into this stanza: forces a WAL switch and waits for that segment. */
    public function check(): void
    {
        $this->run(['check'], self::SHORT_TIMEOUT_SECONDS);
    }

    public function databaseImage(): string
    {
        return $this->executor->databaseImage();
    }

    /**
     * @param list<string> $arguments
     */
    private function run(array $arguments, int $timeoutSeconds): void
    {
        $result = $this->executor->run($arguments, $timeoutSeconds);

        if (!$result->succeeded()) {
            throw PgBackRestCommandFailedException::for($arguments, $result);
        }
    }
}
