<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;

/**
 * Fail-closed rootless-worker gate (GAP-B).
 *
 * In the deploy-in-image / RideColor model one image serves every role and runs as the image's
 * non-root user. If the worker role's supervisord config is rootful — [supervisord] user=root, or a
 * pidfile/socket/logfile under a root-owned dir like /var/run or /var/log/supervisor — supervisord
 * tries to drop privileges as a non-root user and crash-loops ("Can't drop privilege as nonroot
 * user"). Nothing else in the pipeline catches this until the worker color is already failing.
 *
 * This check runs inside the one-shot, which is the SAME image as the worker color, so the config the
 * worker will use is present at the path its workerCommand references. It parses that config and
 * fails closed on a rootful posture. The canonical rootless template ships at
 * {@see self::CANONICAL_SCAFFOLD}.
 *
 * Scope guards (Pass/Skip, never a false Fail):
 *   - external-supervisor topology → Pass (the supervisord runs as its own host user, not the image)
 *   - the one-shot is itself running as root → Pass (root can legitimately drop privileges)
 *   - workerCommand does not invoke supervisord → Pass (a plain console consumer runs as the image user)
 *   - the referenced config is not present in this context → Skip (can't judge; requirement noted)
 */
final class RootlessWorkerCheck implements PreflightCheckInterface
{
    public const CANONICAL_SCAFFOLD = 'packages/Vortos/src/Deploy/Resources/worker/supervisord.rootless.conf';

    private const ROOT_OWNED_DIRS = ['/var/run/', '/var/log/supervisor'];

    /** @var \Closure(): int */
    private \Closure $uidProvider;

    /** @var \Closure(string): ?string */
    private \Closure $configReader;

    /**
     * @param (\Closure(): int)|null       $uidProvider  the one-shot runtime uid; defaults to posix_getuid()
     * @param (\Closure(string): ?string)|null $configReader reads a config path → contents, or null if absent;
     *                                                    injectable so the analyzer is testable without a real FS
     */
    public function __construct(?\Closure $uidProvider = null, ?\Closure $configReader = null)
    {
        $this->uidProvider = $uidProvider ?? static function (): int {
            return \function_exists('posix_getuid') ? posix_getuid() : 0;
        };
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
        return 'worker.command_rootless';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Capability;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        $definition = $context->definition;

        if (!$definition->workerTopology->ridesColor()) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                'external-supervisor topology: the worker runs as its own host user, not the image user',
            );
        }

        if (($this->uidProvider)() === 0) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                'deploy runtime is root; privilege drop is legitimate — no rootless constraint on the worker',
            );
        }

        $command = $definition->runtimeService->workerCommand;
        if (!$this->isSupervisord($command)) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                'workerCommand does not invoke supervisord; it runs as the image user directly',
            );
        }

        $configPath = $this->supervisordConfigPath($command);
        $config = ($this->configReader)($configPath);
        if ($config === null) {
            return PreflightFinding::skip(
                $this->id(),
                $this->category(),
                sprintf(
                    'supervisord config "%s" not present in this context; ensure it is rootless (see %s)',
                    $configPath,
                    self::CANONICAL_SCAFFOLD,
                ),
            );
        }

        $reason = $this->rootfulReason($config);
        if ($reason !== null) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'worker supervisord config is rootful in the single-image (RideColor) model',
                sprintf(
                    'config "%s": %s. The image runs as a non-root user, so supervisord will fail '
                    . '"Can\'t drop privilege as nonroot user" and crash-loop at the worker color.',
                    $configPath,
                    $reason,
                ),
                sprintf(
                    'Make the worker config rootless: remove any [supervisord] user=, put pidfile + '
                    . 'socket under /tmp, and log to stdout. Canonical template: %s.',
                    self::CANONICAL_SCAFFOLD,
                ),
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            sprintf('worker supervisord config "%s" is rootless', $configPath),
        );
    }

    /** @param list<string> $command */
    private function isSupervisord(array $command): bool
    {
        foreach ($command as $arg) {
            if (str_contains($arg, 'supervisord')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The config path referenced by -c <path> / --configuration <path>, or the supervisord default.
     *
     * @param list<string> $command
     */
    private function supervisordConfigPath(array $command): string
    {
        $count = \count($command);
        for ($i = 0; $i < $count; $i++) {
            $arg = $command[$i];
            if (($arg === '-c' || $arg === '--configuration') && isset($command[$i + 1])) {
                return $command[$i + 1];
            }
            if (str_starts_with($arg, '--configuration=')) {
                return substr($arg, \strlen('--configuration='));
            }
            if (str_starts_with($arg, '-c=')) {
                return substr($arg, 3);
            }
        }

        return '/etc/supervisord.conf';
    }

    /** A human reason the config is rootful, or null when it is rootless. */
    private function rootfulReason(string $config): ?string
    {
        // [supervisord] user=root (or any explicit user=) forces a privilege drop.
        if (preg_match('/^\s*user\s*=\s*root\b/mi', $config) === 1) {
            return 'user=root forces supervisord to drop privileges';
        }

        // pidfile / admin socket / logfile under a root-owned directory the non-root uid can't write.
        foreach (['pidfile', 'file', 'logfile'] as $directive) {
            if (preg_match('/^\s*' . $directive . '\s*=\s*(\S+)/mi', $config, $m) === 1) {
                $path = $m[1];
                foreach (self::ROOT_OWNED_DIRS as $rootDir) {
                    if (str_starts_with($path, $rootDir)) {
                        return sprintf('%s=%s is under a root-owned directory', $directive, $path);
                    }
                }
            }
        }

        return null;
    }
}
