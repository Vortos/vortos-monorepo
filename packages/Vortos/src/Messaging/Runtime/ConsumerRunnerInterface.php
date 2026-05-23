<?php

declare(strict_types=1);

namespace Vortos\Messaging\Runtime;

interface ConsumerRunnerInterface
{
    public function run(string $consumerName, int $maxMessages = 0): void;

    public function stop(): void;
}
