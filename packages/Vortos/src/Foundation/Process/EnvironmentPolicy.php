<?php

declare(strict_types=1);

namespace Vortos\Foundation\Process;

/**
 * What a child process inherits from this one. Required on every {@see ProcessSpec}, never defaulted:
 * the application process carries every secret it was configured with, and handing all of them to
 * `psql` or `git` is a decision someone has to make on purpose.
 */
enum EnvironmentPolicy: string
{
    /**
     * Only what a well-behaved CLI needs to find itself and format text (PATH, HOME, locale, TZ, temp
     * dir; plus SystemRoot/PATHEXT/COMSPEC on Windows) and the values the spec declares. The default
     * choice for anything that is handed a credential.
     */
    case Minimal = 'minimal';

    /**
     * The whole parent environment plus the declared values. For tools whose ambient configuration is
     * genuinely environmental and open-ended — docker (DOCKER_HOST), ssh (SSH_AUTH_SOCK), git.
     */
    case Inherit = 'inherit';
}
