<?php

declare(strict_types=1);

namespace Vortos\Docker\Worker;

final readonly class WorkerProcessDefinition
{
    public function __construct(
        public string $name,
        public string $command,
        public string $description,
        public bool $autostart = true,
        public bool $autorestart = true,
        public int $startsecs = 3,
        public int $stopwaitsecs = 30,
        public int $drainDeadline = 25,
        public int $numprocs = 1,
        public ?string $stdoutLogfile = null,
        public ?string $stderrLogfile = null,
        public bool $redirectStderr = true,
    ) {
        $this->assertName($name);

        if ($command === '') {
            throw new \InvalidArgumentException('Worker command cannot be empty.');
        }

        if ($numprocs < 1) {
            throw new \InvalidArgumentException('Worker numprocs must be at least 1.');
        }

        if ($drainDeadline < 1) {
            throw new \InvalidArgumentException('Worker drainDeadline must be at least 1 second.');
        }

        if ($stopwaitsecs < $drainDeadline) {
            throw new \InvalidArgumentException(sprintf(
                'Worker "%s": stopwaitsecs (%d) must be >= drainDeadline (%d) — '
                . 'Supervisor must wait at least as long as the app-side drain deadline.',
                $name,
                $stopwaitsecs,
                $drainDeadline,
            ));
        }
    }

    public function supervisorProgramName(): string
    {
        return $this->name;
    }

    /**
     * Program output goes to the CONTAINER'S STDOUT by default, not to a file.
     *
     * This used to default to /var/log/supervisor/<name>.{out,err}.log, and that default was a trap
     * twice over. A log collector tails container stdout, so a worker container whose programs all
     * wrote to files was completely absent from the log platform — `docker logs` on it returned
     * nothing at all, while the app container beside it shipped normally, which is exactly the kind
     * of asymmetry nobody notices. And supervisord's default rotation is 50 MB x 10 backups PER
     * STREAM, so a worker with fifty programs could accumulate tens of gigabytes inside the
     * container's writable layer — neither a volume nor a host path, and therefore invisible to
     * every retention policy an operator has.
     *
     * Files remain available by passing an explicit path, for the deployment that ships logs some
     * other way.
     */
    public function managedBlock(): string
    {
        $stdout = $this->stdoutLogfile ?? '/dev/stdout';
        $stderr = $this->stderrLogfile ?? '/dev/stderr';

        $lines = [
            sprintf('; <vortos-worker name="%s">', $this->name),
            sprintf('; %s', $this->description),
            sprintf('[program:%s]', $this->supervisorProgramName()),
            sprintf('command=%s', $this->command),
            sprintf('autostart=%s', $this->autostart ? 'true' : 'false'),
            sprintf('autorestart=%s', $this->autorestart ? 'true' : 'false'),
            sprintf('startsecs=%d', $this->startsecs),
            sprintf('stopwaitsecs=%d', $this->stopwaitsecs),
            sprintf('; drain_deadline=%ds', $this->drainDeadline),
        ];

        if ($this->numprocs > 1) {
            $lines[] = sprintf('numprocs=%d', $this->numprocs);
            $lines[] = 'process_name=%(program_name)s_%(process_num)02d';
        }

        // redirect_stderr merges the program's two streams into one. That halves the number of
        // writers sharing the pipe, which matters because writes larger than PIPE_BUF (4096 bytes)
        // from different processes can interleave, and a JSON log line carrying a stack trace
        // exceeds that.
        if ($this->redirectStderr) {
            $lines[] = 'redirect_stderr=true';
        }

        $lines[] = sprintf('stdout_logfile=%s', $stdout);

        // MANDATORY when the target is a device rather than a file: supervisord otherwise tries to
        // rotate /dev/stdout and the program fails to start. Its absence is why a generated block
        // could not be pointed at stdout at all.
        if (str_starts_with($stdout, '/dev/')) {
            $lines[] = 'stdout_logfile_maxbytes=0';
        }

        if (!$this->redirectStderr) {
            $lines[] = sprintf('stderr_logfile=%s', $stderr);

            if (str_starts_with($stderr, '/dev/')) {
                $lines[] = 'stderr_logfile_maxbytes=0';
            }
        }

        $lines[] = sprintf('; </vortos-worker name="%s">', $this->name);

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function assertName(string $name): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name)) {
            throw new \InvalidArgumentException(sprintf('Invalid worker name "%s".', $name));
        }
    }
}
