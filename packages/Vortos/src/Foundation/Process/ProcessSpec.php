<?php

declare(strict_types=1);

namespace Vortos\Foundation\Process;

use InvalidArgumentException;
use Vortos\Foundation\Secret\SecretValue;

/**
 * Everything about a child process that is decided before it starts.
 *
 * The type is the first line of defence: argv holds strings and {@see SensitiveFile}s only, so a
 * {@see SecretValue} cannot be placed on the command line without someone calling reveal() to do it —
 * and {@see ProcessLauncher} refuses any argv string that contains a declared secret anyway.
 *
 * Inputs are validated at runtime rather than trusted from their PHPDoc: callers build argv and env
 * from configuration and user-declared commands, and a type annotation does not stop a SecretValue
 * arriving in argv from an untyped array.
 */
final readonly class ProcessSpec
{
    public const DEFAULT_MAX_OUTPUT_BYTES = 1_048_576;

    /** @var non-empty-list<string|SensitiveFile> */
    public array $argv;

    /** @var array<string, string|SecretValue|SensitiveFile> */
    public array $env;

    /** @var list<SecretValue> */
    public array $redact;

    /**
     * @param array<mixed>      $argv           program then arguments: strings or SensitiveFile only
     * @param float|null        $timeoutSeconds null = no limit (streaming launches must pass null)
     * @param array<mixed>      $env            name => string|SecretValue|SensitiveFile
     * @param array<mixed>      $redact         SecretValues to scrub from captured output that the child is not
     *                                          given directly (e.g. a token it may echo)
     */
    public function __construct(
        array $argv,
        public EnvironmentPolicy $environment,
        public ?float $timeoutSeconds,
        array $env = [],
        public ?string $cwd = null,
        public int $maxOutputBytes = self::DEFAULT_MAX_OUTPUT_BYTES,
        array $redact = [],
    ) {
        $this->argv = self::argv($argv);
        $this->env = self::env($env);
        $this->redact = self::redact($redact);

        if ($timeoutSeconds !== null && $timeoutSeconds <= 0) {
            throw new InvalidArgumentException('Process timeout must be positive, or null for no limit.');
        }
        if ($maxOutputBytes < 1) {
            throw new InvalidArgumentException('maxOutputBytes must be >= 1.');
        }
    }

    /** The program, for messages. Never includes arguments, which is where accidental secrets would be. */
    public function program(): string
    {
        /** @var string */
        return $this->argv[0];
    }

    /**
     * @param array<mixed> $argv
     *
     * @return non-empty-list<string|SensitiveFile>
     */
    private static function argv(array $argv): array
    {
        if ($argv === [] || !array_is_list($argv)) {
            throw new InvalidArgumentException('Process argv must be a non-empty list.');
        }
        if (!\is_string($argv[0]) || $argv[0] === '') {
            throw new InvalidArgumentException('Process argv[0] must be the program name.');
        }

        $valid = [];
        foreach ($argv as $argument) {
            if (!\is_string($argument) && !$argument instanceof SensitiveFile) {
                throw new InvalidArgumentException('Process argv elements must be strings or SensitiveFile; a SecretValue never goes on a command line.');
            }
            $valid[] = $argument;
        }

        return $valid;
    }

    /**
     * @param array<mixed> $env
     *
     * @return array<string, string|SecretValue|SensitiveFile>
     */
    private static function env(array $env): array
    {
        $valid = [];
        foreach ($env as $name => $value) {
            if (!\is_string($name) || $name === '' || str_contains($name, '=') || str_contains($name, "\0")) {
                throw new InvalidArgumentException(sprintf('Invalid environment variable name "%s".', (string) $name));
            }
            if (!\is_string($value) && !$value instanceof SecretValue && !$value instanceof SensitiveFile) {
                throw new InvalidArgumentException(sprintf('Environment variable "%s" must be a string, SecretValue or SensitiveFile.', $name));
            }
            $valid[$name] = $value;
        }

        return $valid;
    }

    /**
     * @param array<mixed> $redact
     *
     * @return list<SecretValue>
     */
    private static function redact(array $redact): array
    {
        $valid = [];
        foreach ($redact as $value) {
            if (!$value instanceof SecretValue) {
                throw new InvalidArgumentException('Redaction values must be SecretValue.');
            }
            $valid[] = $value;
        }

        return $valid;
    }
}
