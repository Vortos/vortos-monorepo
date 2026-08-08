<?php

declare(strict_types=1);

namespace Vortos\Backup\Runtime;

/**
 * Whether a backup-lifecycle occurrence actually happened.
 *
 * A hard failure is not a case here — that throws, so the worker can back off and alert. These are
 * the two non-throwing outcomes, and they must be told apart because only one of them earns the
 * right to advance a schedule's watermark. See {@see LifecycleOutcome}.
 */
enum LifecycleOutcomeStatus: string
{
    case Completed = 'completed';
    case Skipped = 'skipped';
}
