<?php

declare(strict_types=1);

namespace Vortos\Release\Git\Process;

use Symfony\Component\Process\Process;
use Vortos\Release\Git\GitCommandException;
use Vortos\Release\Git\GitRepositoryInterface;
use Vortos\Release\Git\RawCommit;

final class ProcessGitRepository implements GitRepositoryInterface
{
    private const TIMEOUT = 30;
    private const COMMIT_SEPARATOR = '---VORTOS-COMMIT-END---';
    private const FIELD_SEPARATOR = '---VORTOS-FIELD---';

    public function __construct(private readonly string $workingDir) {}

    public function currentSha(): string
    {
        return trim($this->run(['git', 'rev-parse', 'HEAD']));
    }

    public function isClean(): bool
    {
        $output = trim($this->run(['git', 'status', '--porcelain']));

        return $output === '';
    }

    public function currentBranch(): string
    {
        return trim($this->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD']));
    }

    public function tagsMatching(string $prefix): array
    {
        $output = trim($this->run(['git', 'tag', '--list', $prefix . '*', '--sort=-version:refname']));

        if ($output === '') {
            return [];
        }

        return array_values(array_filter(
            explode("\n", $output),
            static fn (string $line) => trim($line) !== '',
        ));
    }

    public function commitsBetween(?string $from, string $to): array
    {
        $format = '%H' . self::FIELD_SEPARATOR . '%aI' . self::FIELD_SEPARATOR . '%B' . self::COMMIT_SEPARATOR;

        $range = $from !== null ? ($from . '..' . $to) : $to;
        $output = $this->run(['git', 'log', $range, '--format=' . $format]);

        if (trim($output) === '') {
            return [];
        }

        $commits = [];
        $chunks = explode(self::COMMIT_SEPARATOR, $output);

        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            $fields = explode(self::FIELD_SEPARATOR, $chunk, 3);
            if (\count($fields) < 3) {
                continue;
            }

            $commits[] = new RawCommit(
                sha: trim($fields[0]),
                rawMessage: trim($fields[2]),
                authoredAt: new \DateTimeImmutable(trim($fields[1])),
            );
        }

        return $commits;
    }

    public function treeShaForPath(string $path): string
    {
        return trim($this->run(['git', 'rev-parse', 'HEAD:' . $path]));
    }

    public function createAnnotatedTag(string $name, string $sha, string $message, bool $sign = false): void
    {
        $cmd = ['git', 'tag', '-a', $name, $sha, '-m', $message];
        if ($sign) {
            $cmd[] = '-s';
        }

        $this->run($cmd);
    }

    public function deleteLocalTag(string $name): void
    {
        $this->run(['git', 'tag', '-d', $name]);
    }

    public function pushTag(string $remote, string $name): void
    {
        $this->run(['git', 'push', $remote, 'refs/tags/' . $name]);
    }

    public function deleteRemoteTag(string $remote, string $name): void
    {
        $this->run(['git', 'push', $remote, ':refs/tags/' . $name]);
    }

    public function tagExists(string $name): bool
    {
        try {
            $this->run(['git', 'rev-parse', 'refs/tags/' . $name]);

            return true;
        } catch (GitCommandException) {
            return false;
        }
    }

    public function tagSha(string $name): ?string
    {
        try {
            return trim($this->run(['git', 'rev-parse', 'refs/tags/' . $name . '^{commit}']));
        } catch (GitCommandException) {
            return null;
        }
    }

    public function verifyTagSignature(string $name): bool
    {
        try {
            $this->run(['git', 'verify-tag', 'refs/tags/' . $name]);

            return true;
        } catch (GitCommandException) {
            return false;
        }
    }

    /** @param list<string> $command */
    private function run(array $command): string
    {
        $process = new Process($command, $this->workingDir);
        $process->setTimeout(self::TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            throw GitCommandException::fromCommand(
                implode(' ', $command),
                $process->getExitCode() ?? 1,
                $process->getErrorOutput(),
            );
        }

        return $process->getOutput();
    }
}
