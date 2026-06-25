<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain\Exception;

use RuntimeException;

/**
 * Base type for every failure the backup concern raises.
 *
 * Catching this catches the whole concern; every concrete failure below extends it
 * so callers can be precise (integrity vs dump vs retention) or broad as needed.
 */
class BackupException extends RuntimeException
{
}
