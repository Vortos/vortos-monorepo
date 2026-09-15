<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

enum CheckpointerWakeOutcome: string
{
    /** Archiving is off, archive_timeout is 0, or the server is a standby: there is no timer to keep. */
    case NotApplicable = 'not_applicable';

    /** Unarchived WAL is within archive_timeout plus grace — PostgreSQL is switching segments itself. */
    case Honoured = 'honoured';

    /** A checkpoint has already run since this start, so the checkpointer's timer is live; waking it again cannot help. */
    case AlreadyAwake = 'already_awake';

    /** archive_command is failing; forcing switches would only queue more segments behind it. The RPO probe pages for this. */
    case ArchivingFailing = 'archiving_failing';

    /** The stranded timer was detected and a CHECKPOINT woke the checkpointer. */
    case Woken = 'woken';

    /** The stranded timer was detected but the CHECKPOINT was refused. */
    case WakeFailed = 'wake_failed';

    /** The server could not be asked; nothing was decided. */
    case Indeterminate = 'indeterminate';

    /** Whether the runtime should log this tick: only when something was done or could not be. */
    public function isReportable(): bool
    {
        return \in_array($this, [self::Woken, self::WakeFailed, self::ArchivingFailing], true);
    }
}
