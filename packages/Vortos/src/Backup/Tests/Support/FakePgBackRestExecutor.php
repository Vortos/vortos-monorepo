<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Support;

use Vortos\Backup\Drill\Container\ExecResult;
use Vortos\Backup\PgBackRest\PgBackRestExecutorInterface;

/** Answers pgBackRest commands from a script; records every invocation. */
final class FakePgBackRestExecutor implements PgBackRestExecutorInterface
{
    /** @var list<list<string>> */
    public array $calls = [];

    /** @var array<string, ExecResult> keyed by the first argument (info, backup, verify, check) */
    public array $answers = [];

    public string $image = 'docker.io/acme/db@sha256:' . 'ab12';

    public function run(array $arguments, int $timeoutSeconds): ExecResult
    {
        $this->calls[] = $arguments;

        return $this->answers[$arguments[0]] ?? new ExecResult(0, '', '');
    }

    public function databaseImage(): string
    {
        return $this->image;
    }

    public function answerInfo(string $json): void
    {
        $this->answers['info'] = new ExecResult(0, $json, '');
    }
}
