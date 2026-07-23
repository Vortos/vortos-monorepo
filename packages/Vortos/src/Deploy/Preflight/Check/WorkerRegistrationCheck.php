<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Docker\Worker\WorkerProcessRegistry;

/**
 * Fail-closed gate on workers that are registered in code but absent from the image's supervisor
 * config.
 *
 * Registering a worker (the vortos.worker tag / a WorkerProcessDefinition service) reads like it
 * makes the worker run. It does not: the supervisor config is a committed file, and a definition
 * only reaches it when someone runs vortos:worker:install. If nobody does, the package ships, the
 * deploy is green, and the worker simply never starts — the queue it drains backs up silently for as
 * long as it takes someone to notice the downstream effect. That is exactly how the alert-delivery
 * drainer was registered and yet drained nothing.
 *
 * vortos:worker:install --check catches this at build time. This catches it at deploy time, which
 * matters because the two can disagree: a config can be regenerated in CI and still not be the one
 * baked into the image being deployed. The check runs inside the one-shot — the SAME image as the
 * worker color — so the file it reads is the file the worker will use.
 *
 * Presence, not equality: the deploy-time question is "will this worker start", so it asserts a
 * [program:<name>] stanza exists. Whether the stanza's body has drifted from the definition is a
 * build-time concern (--check reports it as stale) and is deliberately not a deploy blocker —
 * a diverged comment should not stop a rollout.
 *
 * Coverage, not per-file completeness. Workers are placed across containers deliberately: the
 * scheduler daemon must run on exactly ONE node, so requiring every registered worker in the worker
 * color's config would demand a second scheduler and fail a correct deployment. Images that supervise
 * workers in more than one container list the other configs in VORTOS_WORKER_SUPERVISOR_CONFIGS
 * (comma-separated, paths as they exist inside the image); a worker satisfies this check by appearing
 * in any of them.
 *
 * Scope guards (Pass/Skip, never a false Fail):
 *   - no worker registry available (vortos-docker not installed) → Skip
 *   - no workers registered → Pass (nothing to drop)
 *   - workerCommand does not invoke supervisord → Skip (programs are not how this app runs workers)
 *   - none of the referenced configs are present in this context → Skip (can't judge)
 */
final class WorkerRegistrationCheck implements PreflightCheckInterface
{
    /** @var \Closure(string): ?string */
    private \Closure $configReader;

    /**
     * @param (\Closure(string): ?string)|null $configReader reads a config path → contents, or null if
     *                                                      absent; injectable so this is testable
     *                                                      without a real filesystem
     */
    public function __construct(
        private readonly ?WorkerProcessRegistry $registry = null,
        ?\Closure $configReader = null,
    ) {
        $this->configReader = $configReader ?? static function (string $path): ?string {
            if (!is_file($path) || !is_readable($path)) {
                return null;
            }
            $contents = file_get_contents($path);

            return $contents === false ? null : $contents;
        };
    }

    public function id(): string
    {
        return 'worker.registered_programs';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Capability;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        if ($this->registry === null) {
            return PreflightFinding::skip(
                $this->id(),
                $this->category(),
                'no worker registry available (vortos-docker not installed)',
            );
        }

        if ($this->registry->isEmpty()) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                'no workers registered; nothing can be silently dropped',
            );
        }

        $command = $context->definition->runtimeService->workerCommand;
        $configPath = $this->supervisordConfigPath($command);

        if ($configPath === null) {
            return PreflightFinding::skip(
                $this->id(),
                $this->category(),
                'workerCommand does not invoke supervisord; registered workers are not run as programs',
            );
        }

        $paths = array_values(array_unique(array_merge([$configPath], $this->additionalConfigPaths())));

        $configs = [];
        foreach ($paths as $path) {
            $contents = ($this->configReader)($path);
            if ($contents !== null) {
                $configs[$path] = $contents;
            }
        }

        if ($configs === []) {
            return PreflightFinding::skip(
                $this->id(),
                $this->category(),
                sprintf(
                    'no supervisor config present in this context (looked in: %s); cannot verify worker programs',
                    implode(', ', $paths),
                ),
            );
        }

        $missing = [];
        foreach ($this->registry->all() as $definition) {
            $placed = false;
            foreach ($configs as $contents) {
                if ($this->hasProgram($contents, $definition->supervisorProgramName())) {
                    $placed = true;
                    break;
                }
            }

            if (!$placed) {
                $missing[] = $definition->name;
            }
        }

        if ($missing !== []) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'registered workers have no supervisor program and would never start',
                sprintf(
                    'no [program:] stanza for: %s in any of %s. These are registered as workers but '
                    . 'the image will not run them — the work they own is silently not done.',
                    implode(', ', $missing),
                    implode(', ', array_keys($configs)),
                ),
                'Regenerate and commit the config: php bin/console vortos:worker:install --path=<repo path to the '
                . 'config that should own them>. Add vortos:worker:install --check to CI so the next omission fails '
                . 'the build instead of the deploy. If the worker belongs to another container, list that '
                . 'container config in VORTOS_WORKER_SUPERVISOR_CONFIGS.',
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            sprintf(
                'all %d registered worker(s) have a program in: %s',
                \count($this->registry->all()),
                implode(', ', array_keys($configs)),
            ),
        );
    }

    /**
     * Supervisor configs for containers other than the worker color, as they exist inside the image.
     *
     * @return list<string>
     */
    private function additionalConfigPaths(): array
    {
        $raw = trim((string) ($_ENV['VORTOS_WORKER_SUPERVISOR_CONFIGS'] ?? ''));

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * A supervisord config path from the worker command, or null when the command is not supervisord.
     *
     * @param list<string> $command
     */
    private function supervisordConfigPath(array $command): ?string
    {
        $isSupervisord = false;
        foreach ($command as $arg) {
            if (str_contains($arg, 'supervisord')) {
                $isSupervisord = true;
                break;
            }
        }

        if (!$isSupervisord) {
            return null;
        }

        $count = \count($command);
        for ($i = 0; $i < $count; $i++) {
            $arg = $command[$i];
            if (($arg === '-c' || $arg === '--configuration') && isset($command[$i + 1])) {
                return $command[$i + 1];
            }
            if (str_starts_with($arg, '--configuration=')) {
                return substr($arg, \strlen('--configuration='));
            }
        }

        return '/etc/supervisord.conf';
    }

    private function hasProgram(string $config, string $programName): bool
    {
        return preg_match(
            '/^\s*\[program:' . preg_quote($programName, '/') . '\]\s*$/m',
            $config,
        ) === 1;
    }
}
