<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Caddy;

use Vortos\Deploy\Execution\RemoteCommand;
use Vortos\Deploy\Execution\SshTransportInterface;

final class MountedConfigWriter
{
    public function __construct(
        private readonly string $mountedPath = '/etc/caddy/upstream.json',
        private readonly ?SshTransportInterface $sshTransport = null,
    ) {}

    public function write(string $json): void
    {
        if ($this->sshTransport !== null) {
            $this->writeRemote($json);
        } else {
            $this->writeLocal($json);
        }
    }

    public function read(): ?string
    {
        if ($this->sshTransport !== null) {
            return $this->readRemote();
        }

        return $this->readLocal();
    }

    public function mountedPath(): string
    {
        return $this->mountedPath;
    }

    private function writeLocal(string $json): void
    {
        $dir = \dirname($this->mountedPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create config directory: %s', $dir));
        }

        $tmpPath = $this->mountedPath . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($tmpPath, $json);
        chmod($tmpPath, 0640);
        rename($tmpPath, $this->mountedPath);
    }

    private function writeRemote(string $json): void
    {
        $tmpPath = $this->mountedPath . '.tmp';
        $tmpLocal = tempnam(sys_get_temp_dir(), 'vortos-caddy-');
        if ($tmpLocal === false) {
            throw new \RuntimeException('Failed to create temp file.');
        }

        file_put_contents($tmpLocal, $json);
        $this->sshTransport->copy($tmpLocal, $tmpPath, '0640');
        $this->sshTransport->run(new RemoteCommand(['mv', $tmpPath, $this->mountedPath]));

        @unlink($tmpLocal);
    }

    private function readLocal(): ?string
    {
        if (!file_exists($this->mountedPath)) {
            return null;
        }

        $content = file_get_contents($this->mountedPath);

        return $content !== false && $content !== '' ? $content : null;
    }

    private function readRemote(): ?string
    {
        try {
            $result = $this->sshTransport->run(new RemoteCommand(['cat', $this->mountedPath]));

            return $result->stdout !== '' ? $result->stdout : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
