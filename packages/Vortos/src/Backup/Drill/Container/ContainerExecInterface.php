<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Container;

/**
 * Commands inside a container that is already running — the database's own, for tools that must run
 * beside its data directory (pgBackRest). Separate from {@see ContainerRuntimeInterface}, which only
 * creates and disposes of drill containers, so that capability is granted only where it is wired.
 */
interface ContainerExecInterface
{
    /** The single running container of a compose service; refuses none or several. */
    public function findRunningByComposeService(string $service): RunningContainer;

    /**
     * @param list<string> $command argv; never interpreted by a shell
     */
    public function exec(string $containerId, array $command, int $timeoutSeconds): ExecResult;
}
