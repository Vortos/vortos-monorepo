<?php

declare(strict_types=1);

namespace Vortos\Release\Git;

final class GitCommandException extends \RuntimeException
{
    public static function fromCommand(string $command, int $exitCode, string $error): self
    {
        return new self(sprintf(
            'Git command "%s" failed with exit code %d: %s',
            $command,
            $exitCode,
            trim($error),
        ));
    }
}
