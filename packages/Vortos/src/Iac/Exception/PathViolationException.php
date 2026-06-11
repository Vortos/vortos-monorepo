<?php

declare(strict_types=1);

namespace Vortos\Iac\Exception;

/**
 * An output path escaped the project directory, used a forbidden form,
 * or targeted a file the exporter does not own.
 */
final class PathViolationException extends IacException
{
}
