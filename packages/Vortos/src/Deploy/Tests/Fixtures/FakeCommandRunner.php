<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\Execution\CommandResult;
use Vortos\Deploy\Execution\CommandRunnerInterface;

final class FakeCommandRunner implements CommandRunnerInterface
{
    /** @var list<array{argv: list<string>, stdin: ?string}> */
    public array $calls = [];

    /** @var list<CommandResult> */
    private array $results = [];

    private int $callIndex = 0;

    public function addResult(CommandResult $result): void
    {
        $this->results[] = $result;
    }

    public function run(array $argv, ?string $stdin = null, ?float $timeout = null, array $redactTokens = []): CommandResult
    {
        $this->calls[] = ['argv' => $argv, 'stdin' => $stdin];

        if (isset($this->results[$this->callIndex])) {
            return $this->results[$this->callIndex++];
        }

        $this->callIndex++;

        return new CommandResult(0, '', '', 0.01, $redactTokens);
    }
}
