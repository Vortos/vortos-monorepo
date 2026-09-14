<?php

declare(strict_types=1);

namespace Vortos\Messaging\Runtime;

interface ConsumerRunnerInterface
{
    public function run(string $consumerName, int $maxMessages = 0): void;

    /**
     * Runs several consumers in this one process, each with its own subscription and group.
     *
     * @param list<string> $consumerNames
     * @param int          $maxMessages    stop after this many messages across all consumers (0 = unlimited)
     * @param int          $maxMemoryBytes stop gracefully once the process holds this much memory (0 = no cap),
     *                                     so supervisor restarts it before a slow leak becomes an outage
     */
    public function runMany(array $consumerNames, int $maxMessages = 0, int $maxMemoryBytes = 0): void;

    public function stop(): void;
}
