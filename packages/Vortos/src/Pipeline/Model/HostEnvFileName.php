<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * A bare `*.env` file name inside the remote deploy dir, where compose resolves `./<name>`.
 *
 * Bare (no directory, no traversal, not hidden) because the name is interpolated into the deploy
 * one-shot's command line and must land beside the compose file that references it. Hidden names are
 * refused so a per-service secret can never be declared over the app-wide `.env.prod`.
 */
final readonly class HostEnvFileName
{
    public function __construct(
        public string $value,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*\.env$/', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Host env file names must be a bare, non-hidden *.env name (e.g. "backup-r2.env"), got "%s".',
                $value,
            ));
        }
    }
}
