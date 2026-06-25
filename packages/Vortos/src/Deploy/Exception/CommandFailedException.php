<?php

declare(strict_types=1);

namespace Vortos\Deploy\Exception;

use Vortos\Deploy\Execution\CommandResult;

final class CommandFailedException extends DeployException
{
    public static function fromResult(CommandResult $result, string $context = ''): self
    {
        $message = sprintf(
            'Command failed with exit code %d%s. stderr: %s',
            $result->exitCode,
            $context !== '' ? " ({$context})" : '',
            $result->redactedStderr() !== '' ? $result->redactedStderr() : '(empty)',
        );

        return new self($message, $result->exitCode);
    }
}
