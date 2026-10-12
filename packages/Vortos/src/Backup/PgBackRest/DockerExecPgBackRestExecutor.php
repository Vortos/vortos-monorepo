<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

use Vortos\Backup\Drill\Container\ContainerExecInterface;
use Vortos\Backup\Drill\Container\ExecResult;

/**
 * pgBackRest run inside the database container through the Docker API proxy.
 *
 * The container is looked up on every call, never cached: the database is recreated on a new image now
 * and then, and an exec into a stale id fails, or worse, would reach a container that is going away.
 */
final class DockerExecPgBackRestExecutor implements PgBackRestExecutorInterface
{
    public function __construct(
        private readonly ContainerExecInterface $docker,
        private readonly PgBackRestRepository $repository,
    ) {}

    public function run(array $arguments, int $timeoutSeconds): ExecResult
    {
        $container = $this->docker->findRunningByComposeService($this->repository->databaseService);

        return $this->docker->exec(
            $container->id,
            ['pgbackrest', '--stanza=' . $this->repository->stanza, ...$arguments],
            $timeoutSeconds,
        );
    }

    public function databaseImage(): string
    {
        return $this->docker->findRunningByComposeService($this->repository->databaseService)->image;
    }
}
