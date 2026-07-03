<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\Execution\CommandResult;
use Vortos\Deploy\Execution\RemoteCommand;
use Vortos\Deploy\Execution\SshTransportInterface;

final class FakeSshTransport implements SshTransportInterface
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<array{local: string, remote: string, mode: string}> */
    public array $copies = [];

    /** @var list<CommandResult> */
    private array $results = [];

    private int $callIndex = 0;

    public function addResult(CommandResult $result): void
    {
        $this->results[] = $result;
    }

    public function run(RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;

        if (isset($this->results[$this->callIndex])) {
            return $this->results[$this->callIndex++];
        }

        $this->callIndex++;

        return new CommandResult(0, '', '', 0.01);
    }

    public function copy(string $localPath, string $remotePath, string $mode = '0644'): void
    {
        $this->copies[] = ['local' => $localPath, 'remote' => $remotePath, 'mode' => $mode];
    }

    /** @var list<array{local: int, remote: int}> */
    public array $forwards = [];

    /** @var list<array{local: int, remote: int}> */
    public array $closedForwards = [];

    public int $nextLocalForwardPort = 12019;

    public function openLocalForward(int $remotePort): int
    {
        $local = $this->nextLocalForwardPort++;
        $this->forwards[] = ['local' => $local, 'remote' => $remotePort];

        return $local;
    }

    public function closeLocalForward(int $localPort, int $remotePort): void
    {
        $this->closedForwards[] = ['local' => $localPort, 'remote' => $remotePort];
    }
}
