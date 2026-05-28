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
        public int $numprocs = 1,
        public ?string $stdoutLogfile = null,
        public ?string $stderrLogfile = null,
    ) {
        $this->assertName($name);

        if ($command === '') {
            throw new \InvalidArgumentException('Worker command cannot be empty.');
        }

        if ($numprocs < 1) {
            throw new \InvalidArgumentException('Worker numprocs must be at least 1.');
        }
    }

    public function supervisorProgramName(): string
    {
        return $this->name;
    }

    public function managedBlock(): string
    {
        $stdout = $this->stdoutLogfile ?? sprintf('/var/log/supervisor/%s.out.log', $this->name);
        $stderr = $this->stderrLogfile ?? sprintf('/var/log/supervisor/%s.err.log', $this->name);

        $lines = [
            sprintf('; <vortos-worker name="%s">', $this->name),
            sprintf('; %s', $this->description),
            sprintf('[program:%s]', $this->supervisorProgramName()),
            sprintf('command=%s', $this->command),
            sprintf('autostart=%s', $this->autostart ? 'true' : 'false'),
            sprintf('autorestart=%s', $this->autorestart ? 'true' : 'false'),
            sprintf('startsecs=%d', $this->startsecs),
            sprintf('stopwaitsecs=%d', $this->stopwaitsecs),
        ];

        if ($this->numprocs > 1) {
            $lines[] = sprintf('numprocs=%d', $this->numprocs);
            $lines[] = 'process_name=%(program_name)s_%(process_num)02d';
        }

        $lines[] = sprintf('stdout_logfile=%s', $stdout);
        $lines[] = sprintf('stderr_logfile=%s', $stderr);
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
