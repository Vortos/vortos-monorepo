<?php

declare(strict_types=1);

namespace Vortos\Foundation\Process;

/** How one standard stream of a {@see ProcessLauncher::start()}ed process is wired. */
enum StreamMode: string
{
    /** Connected to the null device. */
    case Null = 'null';

    /** A pipe the caller reads (stdout) or writes (stdin). */
    case Pipe = 'pipe';

    /** Written to a private 0600 file and returned, redacted, by {@see RunningProcess::wait()}. Output streams only. */
    case Capture = 'capture';

    /** This process's own stream — interactive commands. */
    case Inherit = 'inherit';

    /** The controlling terminal (/dev/tty). stdin only; `stty` needs it. */
    case Tty = 'tty';
}
