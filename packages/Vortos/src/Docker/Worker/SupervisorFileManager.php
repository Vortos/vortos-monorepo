<?php

declare(strict_types=1);

namespace Vortos\Docker\Worker;

final class SupervisorFileManager
{
    public const DEFAULT_PATH = 'docker/worker/supervisord.conf';

    public function planInstall(string $projectRoot, WorkerProcessRegistry $registry, ?string $path = null): SupervisorPlan
    {
        $absolutePath = $this->absolutePath($projectRoot, $path);
        $current = is_file($absolutePath) ? (string) file_get_contents($absolutePath) : $this->baseSupervisorConfig();
        $desired = $current;

        foreach ($registry->all() as $definition) {
            $desired = $this->upsertBlock($desired, $definition);
        }

        return new SupervisorPlan(
            $absolutePath,
            $this->changeFor($current, $desired, is_file($absolutePath)),
            $current,
            $desired,
        );
    }

    public function install(
        string $projectRoot,
        WorkerProcessRegistry $registry,
        bool $dryRun = false,
        ?string $path = null,
    ): SupervisorInstallResult {
        $plan = $this->planInstall($projectRoot, $registry, $path);

        if (!$dryRun && $plan->hasChanges()) {
            $this->write($plan->path, $plan->desired);
        }

        return new SupervisorInstallResult($plan, !$dryRun && $plan->hasChanges());
    }

    /**
     * Which registered workers the config on disk does not currently reflect.
     *
     * `planInstall()` answers "would the file change", which is enough to write but not enough to
     * report: a build that fails on drift has to name the worker that would silently never run.
     * Split by kind because the two failures read differently — `missing` is a worker the container
     * has no program for at all (registered in code, absent from the image: it simply never starts),
     * `stale` is a program whose generated body has diverged from the definition (wrong command,
     * wrong drain deadline).
     *
     * @return array{missing: list<string>, stale: list<string>}
     */
    public function drift(string $projectRoot, WorkerProcessRegistry $registry, ?string $path = null): array
    {
        $absolutePath = $this->absolutePath($projectRoot, $path);
        $current = is_file($absolutePath) ? (string) file_get_contents($absolutePath) : '';

        $missing = [];
        $stale = [];

        foreach ($registry->all() as $definition) {
            $pattern = $this->blockPattern($definition->name);

            if (preg_match($pattern, $current, $matches) !== 1) {
                $missing[] = $definition->name;
                continue;
            }

            if (rtrim($matches[0]) !== rtrim($definition->managedBlock())) {
                $stale[] = $definition->name;
            }
        }

        return ['missing' => $missing, 'stale' => $stale];
    }

    /** @param string[] $names */
    public function planRemove(string $projectRoot, array $names, ?string $path = null): SupervisorPlan
    {
        $absolutePath = $this->absolutePath($projectRoot, $path);
        $current = is_file($absolutePath) ? (string) file_get_contents($absolutePath) : $this->baseSupervisorConfig();
        $desired = $current;

        foreach ($names as $name) {
            $desired = $this->removeBlock($desired, $name);
        }

        return new SupervisorPlan(
            $absolutePath,
            $this->changeFor($current, $desired, is_file($absolutePath)),
            $current,
            $desired,
        );
    }

    /** @param string[] $names */
    public function remove(string $projectRoot, array $names, bool $dryRun = false, ?string $path = null): SupervisorInstallResult
    {
        $plan = $this->planRemove($projectRoot, $names, $path);

        if (!$dryRun && $plan->hasChanges()) {
            $this->write($plan->path, $plan->desired);
        }

        return new SupervisorInstallResult($plan, !$dryRun && $plan->hasChanges());
    }

    private function upsertBlock(string $contents, WorkerProcessDefinition $definition): string
    {
        $block = $definition->managedBlock();
        $pattern = $this->blockPattern($definition->name);

        if (preg_match($pattern, $contents) === 1) {
            return (string) preg_replace($pattern, $block, $contents);
        }

        return rtrim($contents) . PHP_EOL . PHP_EOL . rtrim($block) . PHP_EOL;
    }

    private function removeBlock(string $contents, string $name): string
    {
        return rtrim((string) preg_replace($this->blockPattern($name), '', $contents)) . PHP_EOL;
    }

    private function blockPattern(string $name): string
    {
        return sprintf(
            '/; <vortos-worker name="%s">\n.*?; <\/vortos-worker name="%s">\n/s',
            preg_quote($name, '/'),
            preg_quote($name, '/'),
        );
    }

    private function baseSupervisorConfig(): string
    {
        return <<<'CONF'
[supervisord]
nodaemon=true
logfile=/var/log/supervisor/supervisord.log
pidfile=/var/run/supervisord.pid

[unix_http_server]
file=/var/run/supervisor.sock

[rpcinterface:supervisor]
supervisor.rpcinterface_factory = supervisor.rpcinterface:make_main_rpcinterface

[supervisorctl]
serverurl=unix:///var/run/supervisor.sock
CONF . PHP_EOL;
    }

    private function absolutePath(string $projectRoot, ?string $path): string
    {
        $path ??= self::DEFAULT_PATH;

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function changeFor(string $current, string $desired, bool $fileExists): SupervisorChange
    {
        if ($current === $desired) {
            return SupervisorChange::None;
        }

        return $fileExists ? SupervisorChange::Update : SupervisorChange::Create;
    }

    private function write(string $path, string $contents): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $contents, LOCK_EX);
    }
}
