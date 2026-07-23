<?php

declare(strict_types=1);

namespace Vortos\Docker\Worker;

/**
 * The console binary a supervised worker program invokes.
 *
 * Supervisor programs run with no guaranteed working directory, so a worker command must name the
 * console by absolute path — a relative `php bin/console …` resolves against whatever cwd supervisord
 * happened to inherit and the program dies on start. Packages that register workers were each
 * spelling the path inline, and they had already drifted: most hardcoded the app-image path while the
 * alert drainer used a relative one, which would have installed a program that could not start.
 *
 * One default, one override. The default matches the Vortos app-image layout; an image that puts the
 * project elsewhere sets VORTOS_WORKER_CONSOLE_BIN. Read at container-build time only — never call
 * this from a long-lived worker process expecting it to change.
 */
final class WorkerConsole
{
    public const DEFAULT_BIN = '/var/www/html/bin/console';

    public static function bin(): string
    {
        $configured = trim((string) ($_ENV['VORTOS_WORKER_CONSOLE_BIN'] ?? ''));

        return $configured !== '' ? $configured : self::DEFAULT_BIN;
    }

    /**
     * The full supervisor `command=` for a console worker, e.g. `php /var/www/html/bin/console x`.
     */
    public static function command(string $consoleCommand): string
    {
        return sprintf('php %s %s', self::bin(), $consoleCommand);
    }
}
