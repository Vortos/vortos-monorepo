<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

use Vortos\Backup\Drill\Container\ExecResult;

interface PgBackRestExecutorInterface
{
    /**
     * Run `pgbackrest --stanza=<stanza> <arguments>` where the cluster lives.
     *
     * @param list<string> $arguments
     */
    public function run(array $arguments, int $timeoutSeconds): ExecResult;

    /** The image the cluster runs — a drill restores with exactly this pgBackRest and server. */
    public function databaseImage(): string;
}
