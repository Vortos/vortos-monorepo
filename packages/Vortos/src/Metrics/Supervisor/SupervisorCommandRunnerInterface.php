<?php

declare(strict_types=1);

namespace Vortos\Metrics\Supervisor;

/**
 * Runs a local command and returns its stdout.
 *
 * A deliberately tiny seam rather than a reuse of vortos-deploy's CommandRunnerInterface: this
 * package must not depend on vortos-deploy, and the collector needs only "run this and give me the
 * output". It also makes {@see SupervisorStatusReader} testable with no process execution at all.
 */
interface SupervisorCommandRunnerInterface
{
    /**
     * @param list<string> $argv
     *
     * @return string stdout; an empty string when the command could not be run at all
     */
    public function run(array $argv, float $timeoutSeconds): string;
}
