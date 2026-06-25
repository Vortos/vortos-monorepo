<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Driver\Exception;

/**
 * Marker for every exception raised by the ops-kit, so callers can catch the whole
 * family with one type.
 */
interface OpsKitException extends \Throwable
{
}
