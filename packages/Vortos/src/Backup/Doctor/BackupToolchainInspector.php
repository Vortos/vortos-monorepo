<?php

declare(strict_types=1);

namespace Vortos\Backup\Doctor;

use Vortos\Backup\Domain\DatabaseEngine;

/**
 * Probes whether the client binaries a backup engine needs are present on PATH and new enough.
 *
 * This turns the first-real-backup crash ("pg_dump not found", or a version too old to dump a
 * newer server) into a preflight verdict an operator can run in the backup sidecar before wiring
 * the schedule. It is pure and side-effect-free apart from the injected probe, so it unit-tests
 * without real binaries.
 *
 * The engine→binary map and the "dump client major must be ≥ server major" rule are Postgres/Mongo
 * domain knowledge and live here (never a provider concern).
 */
final class BackupToolchainInspector
{
    /**
     * @var \Closure(string): (array{path: string, major: int|null}|null)
     *   Probe one binary: null when absent, else its resolved path + detected major version.
     */
    private \Closure $probe;

    /** @param (\Closure(string): (array{path: string, major: int|null}|null))|null $probe */
    public function __construct(?\Closure $probe = null)
    {
        $this->probe = $probe ?? $this->defaultProbe();
    }

    /**
     * @param int|null $serverMajor the database server major version, when known — enables the
     *                              "client ≥ server" gate. When null, presence is still enforced but
     *                              version is reported, not gated (we cannot judge without a server).
     */
    public function inspect(DatabaseEngine $engine, ?int $serverMajor = null): ToolchainReport
    {
        $findings = [];

        foreach ($this->requirements($engine) as [$name, $required, $versionGated]) {
            $findings[] = $this->probeBinary($name, $required, $versionGated, $serverMajor);
        }

        return new ToolchainReport($engine, $findings, $serverMajor);
    }

    private function probeBinary(string $name, bool $required, bool $versionGated, ?int $serverMajor): BinaryFinding
    {
        $result = ($this->probe)($name);

        if ($result === null) {
            return new BinaryFinding(
                name: $name,
                required: $required,
                present: false,
                path: null,
                detectedMajor: null,
                versionSatisfied: false,
                message: sprintf('%s not found on PATH.', $name),
            );
        }

        $major = $result['major'];
        $satisfied = true;
        $message = sprintf('%s found at %s%s.', $name, $result['path'], $major !== null ? " (major {$major})" : '');

        if ($versionGated && $serverMajor !== null) {
            if ($major === null) {
                $satisfied = false;
                $message = sprintf('%s found at %s but its version could not be determined; need major ≥ %d.', $name, $result['path'], $serverMajor);
            } elseif ($major < $serverMajor) {
                $satisfied = false;
                $message = sprintf('%s major %d is older than the server major %d; it cannot dump/restore a newer server.', $name, $major, $serverMajor);
            }
        }

        return new BinaryFinding(
            name: $name,
            required: $required,
            present: true,
            path: $result['path'],
            detectedMajor: $major,
            versionSatisfied: $satisfied,
            message: $message,
        );
    }

    /**
     * The client binaries each engine needs. Postgres client tools are version-gated (a lower
     * pg_dump cannot dump a newer server); Mongo tools carry an independent (100.x) versioning
     * scheme unrelated to the server major, so they are presence-checked only.
     *
     * @return list<array{0: string, 1: bool, 2: bool}> [name, required, versionGated]
     */
    private function requirements(DatabaseEngine $engine): array
    {
        return match ($engine) {
            DatabaseEngine::Postgres => [
                ['pg_dump', true, true],
                ['pg_restore', true, true],
                ['pg_basebackup', true, true],
            ],
            DatabaseEngine::Mongo => [
                ['mongodump', true, false],
                ['mongorestore', true, false],
            ],
        };
    }

    /** @return \Closure(string): (array{path: string, major: int|null}|null) */
    private function defaultProbe(): \Closure
    {
        return function (string $binary): ?array {
            $path = $this->which($binary);
            if ($path === null) {
                return null;
            }

            return ['path' => $path, 'major' => $this->detectMajor($binary)];
        };
    }

    private function which(string $binary): ?string
    {
        // $binary is always a fixed constant from requirements(), never user input; escaped anyway.
        $found = @shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null');
        if ($found === null) {
            return null;
        }
        $found = trim($found);

        return $found === '' ? null : $found;
    }

    private function detectMajor(string $binary): ?int
    {
        $output = @shell_exec(escapeshellarg($binary) . ' --version 2>/dev/null');
        if (!is_string($output) || $output === '') {
            return null;
        }

        // "pg_dump (PostgreSQL) 18.4" → 18 ; "mongodump version: 100.9.4" → 100
        if (preg_match('/(\d+)(?:\.\d+)+/', $output, $m) === 1) {
            return (int) $m[1];
        }
        if (preg_match('/\b(\d+)\b/', $output, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}
