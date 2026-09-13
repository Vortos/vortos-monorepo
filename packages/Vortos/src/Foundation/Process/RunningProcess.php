<?php

declare(strict_types=1);

namespace Vortos\Foundation\Process;

use LogicException;

/**
 * A process started for streaming. The caller writes {@see stdin()} and/or reads {@see stdout()} to the
 * end, then calls {@see wait()} to learn the exit code — which is how a truncated dump is told apart
 * from a complete one.
 *
 * Captured streams go to private files rather than pipes: a child that writes a lot of stderr while
 * the caller is busy reading stdout would otherwise fill the stderr pipe and deadlock both.
 */
final class RunningProcess
{
    /** Enough of the end of a stream to explain a failure, bounded so a chatty child cannot exhaust memory. */
    private const CAPTURE_TAIL_BYTES = 65_536;

    private bool $finished = false;

    private ?ProcessExit $exit = null;

    /**
     * @param resource                 $handle
     * @param array<int, resource>     $pipes
     * @param array<int, string>       $captures fd => capture file path
     */
    public function __construct(
        private readonly mixed $handle,
        private array $pipes,
        private readonly array $captures,
        private readonly LaunchSecrets $secrets,
        private readonly string $program,
    ) {}

    /** @return resource */
    public function stdin(): mixed
    {
        return $this->pipes[0] ?? throw new LogicException('stdin was not started as a pipe.');
    }

    /** @return resource */
    public function stdout(): mixed
    {
        return $this->pipes[1] ?? throw new LogicException('stdout was not started as a pipe.');
    }

    public function pid(): int
    {
        $status = proc_get_status($this->handle);

        return (int) $status['pid'];
    }

    public function program(): string
    {
        return $this->program;
    }

    /** Close our ends of the pipes, wait for the process, and collect its exit. Idempotent. */
    public function wait(): ProcessExit
    {
        if ($this->exit !== null) {
            return $this->exit;
        }

        $this->closePipes();
        $exitCode = proc_close($this->handle);
        $this->finished = true;

        $this->exit = new ProcessExit(
            $exitCode,
            $this->readCapture(1),
            $this->readCapture(2),
        );
        $this->secrets->cleanup();

        return $this->exit;
    }

    public function __destruct()
    {
        if ($this->finished) {
            return;
        }

        $this->closePipes();
        $status = proc_get_status($this->handle);
        if ($status['running']) {
            proc_terminate($this->handle);
        }
        proc_close($this->handle);
        $this->finished = true;
        $this->secrets->cleanup();
    }

    private function closePipes(): void
    {
        foreach ($this->pipes as $fd => $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
            unset($this->pipes[$fd]);
        }
    }

    private function readCapture(int $fd): string
    {
        $path = $this->captures[$fd] ?? null;
        if ($path === null || !is_file($path)) {
            return '';
        }

        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }
        if ($size > self::CAPTURE_TAIL_BYTES) {
            fseek($handle, -self::CAPTURE_TAIL_BYTES, SEEK_END);
        }
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return $this->secrets->redact($contents);
    }
}
