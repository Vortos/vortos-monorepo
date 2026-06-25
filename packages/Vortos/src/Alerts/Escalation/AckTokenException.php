<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

use RuntimeException;

/** Thrown by {@see AckTokenSigner::verify()} on any tampered, malformed, expired, or wrong-key token. */
final class AckTokenException extends RuntimeException
{
}
