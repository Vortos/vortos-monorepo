<?php

declare(strict_types=1);

namespace Vortos\Deploy\Execution;

interface SshTransportInterface
{
    public function run(RemoteCommand $command): CommandResult;

    public function copy(string $localPath, string $remotePath, string $mode = '0644'): void;
}
