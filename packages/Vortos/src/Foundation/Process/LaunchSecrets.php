<?php

declare(strict_types=1);

namespace Vortos\Foundation\Process;

use Vortos\Foundation\Secret\SecretValue;

/**
 * The secrets of ONE launch: what to refuse on argv, what to scrub from output, and the private files
 * that carry {@see SensitiveFile}s. Created per launch and discarded with it — never a service property.
 *
 * @internal owned by {@see ProcessLauncher}
 */
final class LaunchSecrets
{
    /** Secrets shorter than this are only matched as an entire argument, so "a" does not refuse every argv. */
    private const SUBSTRING_MATCH_MIN_LENGTH = 4;

    /** @var list<string> */
    private array $plaintexts = [];

    private ?string $directory = null;

    /** @var list<string> */
    private array $files = [];

    private function __construct() {}

    public static function for(ProcessSpec $spec, string|SecretValue|null $stdin = null): self
    {
        $secrets = new self();

        foreach ($spec->argv as $argument) {
            if ($argument instanceof SensitiveFile) {
                $secrets->add($argument->contents);
            }
        }
        foreach ($spec->env as $value) {
            if ($value instanceof SecretValue) {
                $secrets->add($value);
            } elseif ($value instanceof SensitiveFile) {
                $secrets->add($value->contents);
            }
        }
        foreach ($spec->redact as $value) {
            $secrets->add($value);
        }
        if ($stdin instanceof SecretValue) {
            $secrets->add($stdin);
        }

        return $secrets;
    }

    /** @throws ProcessLaunchException when a declared secret appears in a plain argv string */
    public function assertAbsentFromArgv(ProcessSpec $spec): void
    {
        foreach ($spec->argv as $position => $argument) {
            if (!\is_string($argument)) {
                continue;
            }
            foreach ($this->plaintexts as $plaintext) {
                $contains = \strlen($plaintext) >= self::SUBSTRING_MATCH_MIN_LENGTH
                    ? str_contains($argument, $plaintext)
                    : $argument === $plaintext;
                if ($contains) {
                    throw ProcessLaunchException::secretOnArgv($spec->program(), $position);
                }
            }
        }
    }

    /**
     * Resolve argv and environment to the strings the child receives, writing sensitive files.
     *
     * @param array<string, string> $baseEnvironment
     *
     * @return array{list<string>, array<string, string>}
     */
    public function materialise(ProcessSpec $spec, array $baseEnvironment): array
    {
        $argv = [];
        foreach ($spec->argv as $argument) {
            $argv[] = $argument instanceof SensitiveFile
                ? $argument->argumentPrefix . $this->write($argument->contents)
                : $argument;
        }

        $env = $baseEnvironment;
        foreach ($spec->env as $name => $value) {
            $env[$name] = match (true) {
                $value instanceof SecretValue => $value->reveal(),
                $value instanceof SensitiveFile => $this->write($value->contents),
                default => $value,
            };
        }

        return [$argv, $env];
    }

    /** A private 0600 file for a stream capture, removed with the other files. */
    public function captureFile(): string
    {
        return $this->create('');
    }

    public function redact(string $output): string
    {
        if ($output === '' || $this->plaintexts === []) {
            return $output;
        }

        $needles = [];
        foreach ($this->plaintexts as $plaintext) {
            foreach ([$plaintext, rawurlencode($plaintext), urlencode($plaintext), base64_encode($plaintext)] as $form) {
                if ($form !== '') {
                    $needles[$form] = true;
                }
            }
        }
        $needles = array_keys($needles);
        // Longest first, so a secret that contains another is scrubbed whole.
        usort($needles, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return str_replace($needles, '***', $output);
    }

    public function cleanup(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                $size = (int) @filesize($file);
                if ($size > 0 && ($handle = @fopen($file, 'r+b')) !== false) {
                    @fwrite($handle, str_repeat("\0", $size));
                    @fflush($handle);
                    @fclose($handle);
                }
                @unlink($file);
            }
        }
        $this->files = [];

        if ($this->directory !== null) {
            @rmdir($this->directory);
            $this->directory = null;
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    private function add(SecretValue $secret): void
    {
        $plaintext = $secret->reveal();
        if ($plaintext !== '') {
            $this->plaintexts[] = $plaintext;
        }
    }

    private function write(SecretValue $contents): string
    {
        return $this->create($contents->reveal());
    }

    private function create(string $contents): string
    {
        if ($this->directory === null) {
            $directory = rtrim(sys_get_temp_dir(), \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . 'vortos-launch-' . bin2hex(random_bytes(12));
            $previous = umask(0o077);
            try {
                if (!@mkdir($directory, 0o700)) {
                    throw ProcessLaunchException::secretFile('cannot create a private directory');
                }
            } finally {
                umask($previous);
            }
            @chmod($directory, 0o700);
            $this->directory = $directory;
        }

        $path = $this->directory . \DIRECTORY_SEPARATOR . 'f' . \count($this->files);
        $previous = umask(0o077);
        try {
            // 'x': fail rather than follow or reuse anything already at this path.
            $handle = @fopen($path, 'xb');
        } finally {
            umask($previous);
        }
        if ($handle === false) {
            throw ProcessLaunchException::secretFile('cannot create a private file');
        }
        $this->files[] = $path;
        @chmod($path, 0o600);

        if ($contents !== '' && fwrite($handle, $contents) !== \strlen($contents)) {
            fclose($handle);
            throw ProcessLaunchException::secretFile('short write');
        }
        fclose($handle);

        return $path;
    }
}
