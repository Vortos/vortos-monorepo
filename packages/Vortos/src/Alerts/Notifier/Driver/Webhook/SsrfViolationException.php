<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier\Driver\Webhook;

use RuntimeException;

final class SsrfViolationException extends RuntimeException
{
}
