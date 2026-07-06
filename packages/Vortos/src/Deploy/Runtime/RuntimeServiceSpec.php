<?php

declare(strict_types=1);

namespace Vortos\Deploy\Runtime;

/**
 * The application's real runtime shape — what the blue/green app (and worker) containers actually
 * run. This is the single source of truth threaded into the cutover compose generation
 * ({@see \Vortos\Deploy\Compose\ComposeFile} / {@see \Vortos\Deploy\Compose\ComposeProjectFactory})
 * and the color endpoint used by both the readiness gate and the Caddy upstream dial.
 *
 * Before this existed the cutover compose was a non-runnable stub: a hardcoded php-server command
 * (which does not exist in a FrankenPHP image), a host-published :8080 port assumption, and no
 * env_file — so the app color could never boot with its DB/secrets/runtime config. Those facts are
 * now declared here and sourced from config/deploy.php.
 *
 * Topology contract (edge-router blue-green): the app colors are **internal-only** — they publish no
 * host ports; the standalone edge (Caddy) owns 80/443 and reverse-proxies to app-<color> on
 * {@see $containerPort} over the shared vortos-net. The framework's canonical readiness path
 * (/health/ready, served by vortos-health) is a fixed contract and is intentionally not part of the
 * spec.
 */
final readonly class RuntimeServiceSpec
{
    public const DEFAULT_COMMAND = ['frankenphp', 'run', '--config', '/etc/frankenphp/Caddyfile', '--adapter', 'caddyfile'];
    public const DEFAULT_WORKER_COMMAND = ['/usr/bin/supervisord', '-c', '/etc/supervisord.conf'];
    public const DEFAULT_CONTAINER_PORT = 8080;
    public const DEFAULT_ENV_FILE = '/opt/vortos/.env.prod';

    /**
     * @param list<string>          $command       the HTTP server argv (must exist in the image)
     * @param list<string>          $workerCommand the worker argv (supervisord/consume)
     * @param list<string>          $envFiles      absolute paths to env files mounted into the color
     * @param array<string, string> $environment   extra app-service environment (e.g. SERVER_NAME)
     * @param list<string>          $networks      docker networks the color attaches to (external)
     * @param list<FileSecret>      $fileSecrets   file-shaped secrets (G8) tmpfs-mounted RO into the color
     * @param ?WorkerHealthcheck    $workerHealthcheck override for the worker service healthcheck (GAP-G);
     *                    null ⇒ resolved by {@see resolvedWorkerHealthcheck()} (supervisord check or disable)
     */
    public function __construct(
        public array $command = self::DEFAULT_COMMAND,
        public int $containerPort = self::DEFAULT_CONTAINER_PORT,
        public array $envFiles = [self::DEFAULT_ENV_FILE],
        public array $workerCommand = self::DEFAULT_WORKER_COMMAND,
        public array $environment = ['SERVER_NAME' => ':8080'],
        public array $networks = ['vortos-net'],
        public array $fileSecrets = [],
        public ?WorkerHealthcheck $workerHealthcheck = null,
    ) {
        $this->assertStringList('command', $command, allowEmpty: false);
        $this->assertStringList('workerCommand', $workerCommand, allowEmpty: false);
        $this->assertStringList('envFiles', $envFiles, allowEmpty: true);
        $this->assertStringList('networks', $networks, allowEmpty: false);

        if ($containerPort < 1 || $containerPort > 65535) {
            throw new \InvalidArgumentException(sprintf(
                'RuntimeServiceSpec.containerPort must be 1-65535, got %d.',
                $containerPort,
            ));
        }

        foreach ($envFiles as $file) {
            if (!str_starts_with($file, '/')) {
                throw new \InvalidArgumentException(sprintf(
                    'RuntimeServiceSpec.envFiles entries must be absolute paths (the cutover compose '
                    . 'is written to /tmp on the target, so relative env_file paths do not resolve); got "%s".',
                    $file,
                ));
            }
        }

        foreach (array_keys($environment) as $key) {
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException('RuntimeServiceSpec.environment keys must be non-empty strings.');
            }
        }

        $seenContainerPaths = [];
        foreach ($fileSecrets as $fileSecret) {
            if (!$fileSecret instanceof FileSecret) {
                throw new \InvalidArgumentException('RuntimeServiceSpec.fileSecrets entries must be FileSecret instances.');
            }
            if (isset($seenContainerPaths[$fileSecret->containerPath])) {
                throw new \InvalidArgumentException(sprintf(
                    'RuntimeServiceSpec.fileSecrets has two secrets targeting the same container path "%s".',
                    $fileSecret->containerPath,
                ));
            }
            $seenContainerPaths[$fileSecret->containerPath] = true;
        }
    }

    /**
     * The worker service healthcheck to emit (GAP-G) — the explicit app override if set, otherwise a
     * real 'supervisorctl' check when the worker runs supervisord (the framework default), otherwise a
     * disable. Either way the worker never inherits the base image's HTTP 'HEALTHCHECK'.
     */
    public function resolvedWorkerHealthcheck(): WorkerHealthcheck
    {
        if ($this->workerHealthcheck !== null) {
            return $this->workerHealthcheck;
        }

        return $this->workerRunsSupervisord()
            ? WorkerHealthcheck::supervisord()
            : WorkerHealthcheck::disabled();
    }

    private function workerRunsSupervisord(): bool
    {
        foreach ($this->workerCommand as $arg) {
            if (str_contains($arg, 'supervisord')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'command' => $this->command,
            'container_port' => $this->containerPort,
            'env_files' => $this->envFiles,
            'worker_command' => $this->workerCommand,
            'environment' => $this->environment,
            'networks' => $this->networks,
            'file_secrets' => array_map(static fn (FileSecret $s): array => $s->toArray(), $this->fileSecrets),
            'worker_healthcheck' => $this->resolvedWorkerHealthcheck()->toArray(),
        ];
    }

    /**
     * @param list<string> $value
     */
    private function assertStringList(string $field, array $value, bool $allowEmpty): void
    {
        if (!$allowEmpty && $value === []) {
            throw new \InvalidArgumentException(sprintf('RuntimeServiceSpec.%s must not be empty.', $field));
        }

        foreach ($value as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new \InvalidArgumentException(sprintf('RuntimeServiceSpec.%s entries must be non-empty strings.', $field));
            }
        }
    }
}
